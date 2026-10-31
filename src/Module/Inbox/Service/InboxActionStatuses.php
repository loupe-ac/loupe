<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\BridgeCommand;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\BridgeCommandRepository;
use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Service\BridgeLiveness;
use App\Module\Bridge\ValueObject\BridgeCommandState;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Inbox\Entity\InboxAction;
use App\Module\Inbox\Entity\InboxActionKind;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Repository\InboxActionRepository;
use App\Module\Inbox\View\InboxActionState;
use App\Module\Inbox\View\InboxActionStatus;
use Symfony\Component\Uid\Uuid;

/**
 * Reads the status line of each action of a list of items. The status comes from the bridge command,
 * the work request or the run that the action started, so the inbox keeps no second copy. A dismiss, and a resume that
 * never got its command, have no line. Reads are batched: no query per action.
 */
final readonly class InboxActionStatuses
{
    public function __construct(
        private InboxActionRepository $inboxActions,
        private BridgeCommandRepository $bridgeCommands,
        private WorkerRunRepository $workerRuns,
        private WorkRequestRepository $workRequests,
        private BridgeRepository $bridges,
        private BridgeLiveness $liveness,
        private SessionBridges $sessionBridges,
    ) {
    }

    /**
     * @param list<InboxItem> $items
     *
     * @return array<string, list<InboxActionStatus>> keyed by the RFC 4122 item id, oldest action first
     */
    public function forItems(array $items): array
    {
        $byItem = $this->inboxActions->findByItems($items);
        $commandIds = [];
        foreach ($byItem as $actions) {
            foreach ($actions as $action) {
                if (InboxActionKind::ResumeRun === $action->kind && null !== $action->bridgeCommandId) {
                    $commandIds[] = $action->bridgeCommandId;
                }
            }
        }
        $commands = $this->bridgeCommands->findByIds($commandIds);
        $quiet = $this->quietBridges($commands);
        $continuations = $this->continuations($commands);
        $agentRequestIds = [];
        $entryRequestIds = [];
        foreach ($byItem as $actions) {
            foreach ($actions as $action) {
                if (null === $action->workRequestId) {
                    continue;
                }
                if (InboxActionKind::SendAgent === $action->kind) {
                    $agentRequestIds[] = $action->workRequestId;
                } elseif (InboxActionKind::AddEntry === $action->kind) {
                    $entryRequestIds[] = $action->workRequestId;
                }
            }
        }
        $agentRequests = $this->workRequests->findByIds([...$agentRequestIds, ...$entryRequestIds]);
        $agentRuns = $this->workerRuns->findNewestOfWorkRequests($agentRequestIds);
        $offlineProjects = $this->offlineProjects($agentRequests);
        [$requests, $requestRuns, $sessionOnline] = $this->sessionReads($byItem);

        $statuses = [];
        foreach ($items as $item) {
            $lines = [];
            foreach ($byItem[(string) $item->id] ?? [] as $action) {
                $line = match ($action->kind) {
                    InboxActionKind::RetryCard => new InboxActionStatus($action, InboxActionState::Retried, 'inbox.action.status.retried', at: $action->requestedAt),
                    InboxActionKind::HoldCard => new InboxActionStatus($action, InboxActionState::Held, 'inbox.action.status.held', at: $action->requestedAt),
                    InboxActionKind::ResumeRun => $this->resumeStatus($action, $commands, $quiet, $continuations),
                    InboxActionKind::SendAgent => $this->sendAgentStatus($action, $agentRequests, $agentRuns, $offlineProjects),
                    InboxActionKind::AddEntry => $this->addEntryStatus($action, $agentRequests, $offlineProjects),
                    InboxActionKind::StartSession => $this->sessionStatus($action, $requests, $requestRuns, $sessionOnline),
                    default => null,
                };
                if (null !== $line) {
                    $lines[] = $line;
                }
            }
            $statuses[(string) $item->id] = $lines;
        }

        return $statuses;
    }

    /**
     * The work requests of the session actions, the runs they started, and whether a live bridge can take each open request.
     *
     * @param array<string, list<InboxAction>> $byItem
     *
     * @return array{array<string, WorkRequest>, array<string, WorkerRun>, array<string, bool>}
     */
    private function sessionReads(array $byItem): array
    {
        $ids = [];
        foreach ($byItem as $actions) {
            foreach ($actions as $action) {
                if (InboxActionKind::StartSession === $action->kind && null !== $action->workRequestId) {
                    $ids[] = $action->workRequestId;
                }
            }
        }
        $requests = $this->workRequests->findByIds($ids);

        $runs = [];
        foreach ($this->workerRuns->findOfSubjectWorkRequests(array_values($requests)) as $run) {
            $runs[(string) $run->workRequestId] ??= $run;
        }

        $projects = [];
        foreach ($requests as $request) {
            if (WorkRequestState::Open === $request->state) {
                $projects[(string) $request->project->id] = $request->project;
            }
        }
        $live = $this->sessionBridges->liveFor(array_values($projects));

        $online = [];
        foreach ($requests as $request) {
            if (WorkRequestState::Open === $request->state) {
                $bridges = $live[(string) $request->project->id] ?? [];
                $online[(string) $request->id] = InboxItem::SUBJECT_TYPE === $request->subjectType
                    ? array_any($bridges, SessionBridges::opensItemSessions(...))
                    : [] !== $bridges;
            }
        }

        return [$requests, $runs, $online];
    }

    /**
     * @param array<string, WorkRequest> $requests      keyed by the RFC 4122 request id
     * @param array<string, WorkerRun>   $requestRuns   the newest run of each request, keyed by the RFC 4122 request id
     * @param array<string, bool>        $sessionOnline keyed by the RFC 4122 request id
     */
    private function sessionStatus(InboxAction $action, array $requests, array $requestRuns, array $sessionOnline): ?InboxActionStatus
    {
        $request = null === $action->workRequestId ? null : ($requests[(string) $action->workRequestId] ?? null);
        if (null === $request) {
            return null;
        }
        $settledAt = $request->settledAt ?? $action->requestedAt;

        if (null !== $action->cancelledAt || WorkRequestState::Cancelled === $request->state) {
            return new InboxActionStatus($action, InboxActionState::Cancelled, 'inbox.action.status.cancelled', at: $action->cancelledAt ?? $settledAt);
        }
        $run = $requestRuns[(string) $request->id] ?? null;
        if (null !== $run) {
            return new InboxActionStatus($action, InboxActionState::Running, $run->state->translationKey(), at: $run->receivedAt);
        }

        return match ($request->state) {
            WorkRequestState::Open => new InboxActionStatus($action, InboxActionState::Queued, 'inbox.action.status.queued', cancellable: true, offline: !($sessionOnline[(string) $request->id] ?? false), at: $action->requestedAt),
            WorkRequestState::Expired => new InboxActionStatus($action, InboxActionState::Expired, 'inbox.action.status.expired', at: $settledAt),
            WorkRequestState::Refused => null === $request->reason
                ? new InboxActionStatus($action, InboxActionState::Refused, 'inbox.action.status.refused_no_reason', at: $settledAt)
                : new InboxActionStatus($action, InboxActionState::Refused, 'inbox.action.status.refused', ['%reason%' => $request->reason], at: $settledAt),
            default => new InboxActionStatus($action, InboxActionState::Preparing, 'inbox.action.status.preparing', at: $settledAt),
        };
    }

    /**
     * @param array<string, BridgeCommand>   $commands
     * @param array<string, bool>            $quiet         keyed by the RFC 4122 bridge id
     * @param array<string, list<WorkerRun>> $continuations keyed by the RFC 4122 stopped run id, newest first
     */
    private function resumeStatus(InboxAction $action, array $commands, array $quiet, array $continuations): ?InboxActionStatus
    {
        $command = null === $action->bridgeCommandId ? null : ($commands[(string) $action->bridgeCommandId] ?? null);
        if (null === $command) {
            return null;
        }
        $settledAt = $command->settledAt ?? $action->requestedAt;

        if (null !== $action->cancelledAt || BridgeCommandState::Cancelled === $command->state) {
            return new InboxActionStatus($action, InboxActionState::Cancelled, 'inbox.action.status.cancelled', at: $action->cancelledAt ?? $settledAt);
        }

        return match ($command->state) {
            BridgeCommandState::Pending => new InboxActionStatus($action, InboxActionState::Queued, 'inbox.action.status.queued', cancellable: true, offline: $quiet[$command->bridgeId->toRfc4122()] ?? true, at: $action->requestedAt),
            BridgeCommandState::Expired => new InboxActionStatus($action, InboxActionState::Expired, 'inbox.action.status.expired', at: $settledAt),
            BridgeCommandState::Refused => null === $command->reason
                ? new InboxActionStatus($action, InboxActionState::Refused, 'inbox.action.status.refused_no_reason', at: $settledAt)
                : new InboxActionStatus($action, InboxActionState::Refused, 'inbox.action.status.refused', ['%reason%' => $command->reason], at: $settledAt),
            default => $this->settledStatus($action, $command, $continuations, $settledAt),
        };
    }

    /**
     * @param array<string, WorkRequest> $requests        keyed by the RFC 4122 request id
     * @param array<string, WorkerRun>   $runs            the newest run of each request, keyed by the RFC 4122 request id
     * @param array<string, bool>        $offlineProjects keyed by the RFC 4122 project id
     */
    private function sendAgentStatus(InboxAction $action, array $requests, array $runs, array $offlineProjects): ?InboxActionStatus
    {
        $request = null === $action->workRequestId ? null : ($requests[(string) $action->workRequestId] ?? null);
        if (null === $request) {
            return null;
        }
        $settledAt = $request->settledAt ?? $action->requestedAt;

        if (null !== $action->cancelledAt || WorkRequestState::Cancelled === $request->state) {
            return new InboxActionStatus($action, InboxActionState::Cancelled, 'inbox.action.status.cancelled', at: $action->cancelledAt ?? $settledAt);
        }
        $run = $runs[(string) $request->id] ?? null;
        if (null !== $run) {
            return new InboxActionStatus($action, InboxActionState::Running, $run->state->translationKey(), at: $run->receivedAt);
        }

        return match ($request->state) {
            WorkRequestState::Open => new InboxActionStatus($action, InboxActionState::Queued, 'inbox.action.status.queued', cancellable: true, offline: $offlineProjects[(string) $request->project->id] ?? true, at: $action->requestedAt),
            WorkRequestState::Expired => new InboxActionStatus($action, InboxActionState::Expired, 'inbox.action.status.expired', at: $settledAt),
            WorkRequestState::Refused => null === $request->reason
                ? new InboxActionStatus($action, InboxActionState::Refused, 'inbox.action.status.refused_no_reason', at: $settledAt)
                : new InboxActionStatus($action, InboxActionState::Refused, 'inbox.action.status.refused', ['%reason%' => $request->reason], at: $settledAt),
            default => new InboxActionStatus($action, InboxActionState::Preparing, 'inbox.action.status.preparing', at: $request->settledAt ?? $action->requestedAt),
        };
    }

    /**
     * The bridge starts no run for an added entry, so the status follows the work request alone.
     *
     * @param array<string, WorkRequest> $requests        keyed by the RFC 4122 request id
     * @param array<string, bool>        $offlineProjects keyed by the RFC 4122 project id
     */
    private function addEntryStatus(InboxAction $action, array $requests, array $offlineProjects): ?InboxActionStatus
    {
        $request = null === $action->workRequestId ? null : ($requests[(string) $action->workRequestId] ?? null);
        if (null === $request) {
            return null;
        }
        $settledAt = $request->settledAt ?? $action->requestedAt;

        if (null !== $action->cancelledAt || WorkRequestState::Cancelled === $request->state) {
            return new InboxActionStatus($action, InboxActionState::Cancelled, 'inbox.action.status.cancelled', at: $action->cancelledAt ?? $settledAt);
        }

        return match ($request->state) {
            WorkRequestState::Open => new InboxActionStatus($action, InboxActionState::Queued, 'inbox.action.status.queued', cancellable: true, offline: $offlineProjects[(string) $request->project->id] ?? true, at: $action->requestedAt),
            WorkRequestState::Done => new InboxActionStatus($action, InboxActionState::Added, 'inbox.action.status.added', at: $settledAt),
            WorkRequestState::Expired => new InboxActionStatus($action, InboxActionState::Expired, 'inbox.action.status.expired', at: $settledAt),
            WorkRequestState::Refused => null === $request->reason
                ? new InboxActionStatus($action, InboxActionState::Refused, 'inbox.action.status.refused_no_reason', at: $settledAt)
                : new InboxActionStatus($action, InboxActionState::Refused, 'inbox.action.status.refused', ['%reason%' => $request->reason], at: $settledAt),
            default => new InboxActionStatus($action, InboxActionState::Preparing, 'inbox.action.status.preparing', at: $settledAt),
        };
    }

    /**
     * Whether no bridge that follows the project and takes work requests sends its heartbeat, for the projects of the open requests. One read of bridges and one liveness read per project.
     *
     * @param array<string, WorkRequest> $requests
     *
     * @return array<string, bool> keyed by the RFC 4122 project id
     */
    private function offlineProjects(array $requests): array
    {
        $projects = [];
        foreach ($requests as $request) {
            if (WorkRequestState::Open === $request->state) {
                $projects[(string) $request->project->id] = $request->project;
            }
        }

        $offline = [];
        foreach ($projects as $key => $project) {
            $ids = array_map(
                static fn (Bridge $bridge): Uuid => $bridge->id,
                array_values(array_filter($this->bridges->findFollowingProject($project), static fn (Bridge $bridge): bool => $bridge->takesWorkRequests())),
            );
            $offline[$key] = !array_any($this->liveness->forOwner($project->owner, $ids), static fn ($status): bool => !$status->quiet);
        }

        return $offline;
    }

    /** @param array<string, list<WorkerRun>> $continuations */
    private function settledStatus(InboxAction $action, BridgeCommand $command, array $continuations, \DateTimeImmutable $settledAt): InboxActionStatus
    {
        // A run that reported before the click belongs to an earlier resume of the same run.
        $run = array_find(
            $continuations[(string) $command->workerRun->id] ?? [],
            static fn (WorkerRun $continuation): bool => $continuation->receivedAt >= $command->requestedAt,
        );
        if (null === $run) {
            return new InboxActionStatus($action, InboxActionState::Resumed, 'inbox.action.status.resumed', at: $settledAt);
        }

        return new InboxActionStatus($action, InboxActionState::Running, $run->state->translationKey(), at: $run->receivedAt);
    }

    /**
     * One liveness read per owner, for the bridges of the pending commands.
     *
     * @param array<string, BridgeCommand> $commands
     *
     * @return array<string, bool> keyed by the RFC 4122 bridge id
     */
    private function quietBridges(array $commands): array
    {
        $owners = [];
        $bridgeIds = [];
        foreach ($commands as $command) {
            if (BridgeCommandState::Pending === $command->state) {
                $ownerKey = (string) $command->owner->id;
                $owners[$ownerKey] = $command->owner;
                $bridgeIds[$ownerKey][$command->bridgeId->toRfc4122()] = $command->bridgeId;
            }
        }

        $quiet = [];
        foreach ($owners as $ownerKey => $owner) {
            foreach ($this->liveness->forOwner($owner, array_values($bridgeIds[$ownerKey])) as $bridgeKey => $status) {
                $quiet[$bridgeKey] = $status->quiet;
            }
        }

        return $quiet;
    }

    /**
     * The runs that continue the runs of the settled commands, newest first.
     *
     * @param array<string, BridgeCommand> $commands
     *
     * @return array<string, list<WorkerRun>> keyed by the RFC 4122 stopped run id
     */
    private function continuations(array $commands): array
    {
        $stopped = [];
        foreach ($commands as $command) {
            if (BridgeCommandState::Done === $command->state) {
                $stopped[(string) $command->workerRun->id] = $command->workerRun;
            }
        }

        $byRun = [];
        foreach (array_reverse($this->workerRuns->findContinuationsOf(array_values($stopped))) as $run) {
            $byRun[(string) ($run->continuesRun->id ?? throw new \LogicException('A continuation has a stopped run.'))][] = $run;
        }

        return $byRun;
    }
}
