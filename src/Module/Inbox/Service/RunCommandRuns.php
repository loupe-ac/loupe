<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Inbox\Entity\InboxAction;
use App\Module\Inbox\Entity\InboxActionKind;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Repository\InboxActionRepository;
use App\Module\Inbox\View\RunCommandRun;
use App\Module\Inbox\View\RunCommandRunState;

/**
 * Reads the newest command run of each inbox item from the work request that its action opened and the
 * run that the bridge reported for it. The inbox keeps no second copy. Reads are batched: no query per item.
 */
final readonly class RunCommandRuns
{
    public function __construct(
        private InboxActionRepository $inboxActions,
        private WorkRequestRepository $workRequests,
        private WorkerRunRepository $workerRuns,
    ) {
    }

    /**
     * @param list<InboxItem> $items
     *
     * @return array<string, RunCommandRun> keyed by the RFC 4122 item id, for the items that have a run-command action
     */
    public function forItems(array $items): array
    {
        $newest = [];
        foreach ($this->inboxActions->findByItems($items) as $itemId => $actions) {
            $commands = array_filter($actions, static fn (InboxAction $action): bool => InboxActionKind::RunCommand === $action->kind);
            if ([] !== $commands) {
                $newest[$itemId] = array_last($commands);
            }
        }

        $requestIds = array_values(array_filter(array_map(static fn (InboxAction $action) => $action->workRequestId, $newest)));
        $requests = $this->workRequests->findByIds($requestIds);
        $runs = $this->workerRuns->findNewestOfWorkRequests($requestIds);

        $states = [];
        foreach ($newest as $itemId => $action) {
            $request = null === $action->workRequestId ? null : ($requests[(string) $action->workRequestId] ?? null);
            if (null === $request) {
                continue;
            }
            $states[$itemId] = $this->runOf($action, $request, $runs[(string) $request->id] ?? null);
        }

        return $states;
    }

    /**
     * The items that have an add of a command entry queued or claimed.
     *
     * @param list<InboxItem> $items
     *
     * @return array<string, true> keyed by the RFC 4122 item id
     */
    public function itemsAddingEntry(array $items): array
    {
        $byItem = [];
        $requestIds = [];
        foreach ($this->inboxActions->findByItems($items) as $itemId => $actions) {
            foreach ($actions as $action) {
                if (InboxActionKind::AddEntry === $action->kind && null === $action->cancelledAt && null !== $action->workRequestId) {
                    $byItem[$itemId][] = (string) $action->workRequestId;
                    $requestIds[] = $action->workRequestId;
                }
            }
        }

        $requests = $this->workRequests->findByIds($requestIds);
        $adding = [];
        foreach ($byItem as $itemId => $ids) {
            if (array_any($ids, static fn (string $id): bool => isset($requests[$id]) && $requests[$id]->state->isLive())) {
                $adding[$itemId] = true;
            }
        }

        return $adding;
    }

    private function runOf(InboxAction $action, WorkRequest $request, ?WorkerRun $run): RunCommandRun
    {
        if (null !== $action->cancelledAt || WorkRequestState::Cancelled === $request->state) {
            return new RunCommandRun($action, RunCommandRunState::Cancelled, at: $action->cancelledAt ?? $request->settledAt);
        }
        if (null !== $run) {
            $state = match (true) {
                WorkerRunState::Succeeded === $run->state => RunCommandRunState::Succeeded,
                $run->state->isOpen() => RunCommandRunState::Running,
                default => RunCommandRunState::Failed,
            };

            return new RunCommandRun($action, $state, exitCode: $run->exitCode, outputLines: RunCommandRun::tail($run->output), at: $run->endedAt ?? $run->receivedAt);
        }

        return match ($request->state) {
            WorkRequestState::Open => new RunCommandRun($action, RunCommandRunState::Queued, at: $action->requestedAt),
            WorkRequestState::Refused => new RunCommandRun($action, RunCommandRunState::Refused, reason: $request->reason, at: $request->settledAt),
            WorkRequestState::Expired => new RunCommandRun($action, RunCommandRunState::Expired, at: $request->settledAt),
            default => new RunCommandRun($action, RunCommandRunState::Running, at: $request->settledAt ?? $action->requestedAt),
        };
    }
}
