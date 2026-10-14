<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Bridge\Entity\BridgeCommand;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\BridgeCommandRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Service\BridgeLiveness;
use App\Module\Bridge\ValueObject\BridgeCommandState;
use App\Module\Inbox\Entity\InboxAction;
use App\Module\Inbox\Entity\InboxActionKind;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Repository\InboxActionRepository;
use App\Module\Inbox\View\InboxActionState;
use App\Module\Inbox\View\InboxActionStatus;

/**
 * Reads the status line of each action of a list of items. The status comes from the bridge command
 * or the run that the action started, so the inbox keeps no second copy. A dismiss, and a resume that
 * never got its command, have no line. Reads are batched: no query per action.
 */
final readonly class InboxActionStatuses
{
    public function __construct(
        private InboxActionRepository $actions,
        private BridgeCommandRepository $bridgeCommands,
        private WorkerRunRepository $workerRuns,
        private BridgeLiveness $liveness,
    ) {
    }

    /**
     * @param list<InboxItem> $items
     *
     * @return array<string, list<InboxActionStatus>> keyed by the RFC 4122 item id, oldest action first
     */
    public function forItems(array $items): array
    {
        $byItem = $this->actions->findByItems($items);
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

        $statuses = [];
        foreach ($items as $item) {
            $lines = [];
            foreach ($byItem[(string) $item->id] ?? [] as $action) {
                $line = match ($action->kind) {
                    InboxActionKind::RetryCard => new InboxActionStatus($action, InboxActionState::Retried, 'inbox.action.status.retried', at: $action->requestedAt),
                    InboxActionKind::ResumeRun => $this->resumeStatus($action, $commands, $quiet, $continuations),
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
            $byRun[(string) ($run->continuesRun?->id ?? throw new \LogicException('A continuation has a stopped run.'))][] = $run;
        }

        return $byRun;
    }
}
