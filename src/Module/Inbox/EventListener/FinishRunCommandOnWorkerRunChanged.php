<?php

declare(strict_types=1);

namespace App\Module\Inbox\EventListener;

use App\Exception\DomainErrors;
use App\Module\Bridge\Command\RequestBridgeCommandCommand;
use App\Module\Bridge\Command\RequestBridgeCommandHandler;
use App\Module\Bridge\Event\WorkerRunChanged;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\ValueObject\BridgeCommandCause;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Inbox\Command\MarkInboxItemDoneCommand;
use App\Module\Inbox\Command\MarkInboxItemDoneHandler;
use App\Module\Inbox\Entity\InboxAction;
use App\Module\Inbox\Entity\InboxActionKind;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Repository\InboxActionRepository;
use App\Module\Inbox\Repository\InboxCardWatchRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * A command that the owner ran from the inbox succeeded. A to-do is done then, which closes its ask and
 * resumes the asking worker as a click on Mark done does. A wait item resumes the stopped run it waited on.
 * A command that failed or was refused changes nothing, and the item stays open for another run.
 * The event comes on every report of the run, so a second pass finds the item closed or the resume queued.
 */
#[AsEventListener]
final readonly class FinishRunCommandOnWorkerRunChanged
{
    public function __construct(
        private InboxActionRepository $inboxActions,
        private WorkerRunRepository $workerRuns,
        private InboxCardWatchRepository $inboxCardWatches,
        private MarkInboxItemDoneHandler $markDone,
        private RequestBridgeCommandHandler $requestBridgeCommand,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(WorkerRunChanged $event): void
    {
        $actions = array_filter(
            $this->inboxActions->findByRunIds($event->runIds),
            static fn (InboxAction $action): bool => InboxActionKind::RunCommand === $action->kind
                && null !== $action->workRequestId
                && null === $action->cancelledAt
                && InboxItemState::Open === $action->item->state,
        );
        if ([] === $actions) {
            return;
        }

        $runs = $this->workerRuns->findNewestOfWorkRequests(array_values(array_map(static fn (InboxAction $action) => $action->workRequestId ?? throw new \LogicException('The filter kept actions with a request.'), $actions)));
        foreach ($actions as $action) {
            $run = $runs[(string) $action->workRequestId] ?? null;
            if (null === $run || WorkerRunState::Succeeded !== $run->state) {
                continue;
            }

            if (InboxItemKind::Todo === $action->item->kind) {
                $this->closeTodo($action);
            } else {
                $this->resumeWaiting($action);
            }
        }
    }

    private function closeTodo(InboxAction $action): void
    {
        try {
            ($this->markDone)(new MarkInboxItemDoneCommand($action->item, commandSucceeded: true));
        } catch (DomainErrors $e) {
            $this->logger->info('inbox.run_command_close_skipped', ['itemId' => (string) $action->item->id, 'actionId' => (string) $action->id, 'errors' => array_values($e->errors)]);
        }
    }

    private function resumeWaiting(InboxAction $action): void
    {
        if (null !== $action->bridgeCommandId) {
            return;
        }

        $wait = array_find(
            $this->inboxCardWatches->findOneForItem($action->item)?->waits->toArray() ?? [],
            static fn ($wait): bool => null !== $action->waitId && $action->waitId->equals($wait->id),
        );
        $run = null === $wait?->runId ? null : $this->workerRuns->find($wait->runId);
        if (null === $run) {
            $this->logger->info('inbox.run_command_resume_skipped', ['itemId' => (string) $action->item->id, 'actionId' => (string) $action->id, 'reason' => 'no_run']);

            return;
        }

        try {
            $command = ($this->requestBridgeCommand)(new RequestBridgeCommandCommand($run, BridgeCommandKind::ResumeRun, $action->requestedBy, null, BridgeCommandCause::InboxAction, $action->id, $action->item->number, $action->item->id));
        } catch (DomainErrors $e) {
            $this->logger->info('inbox.run_command_resume_skipped', ['itemId' => (string) $action->item->id, 'actionId' => (string) $action->id, 'errors' => array_values($e->errors)]);

            return;
        }

        $action->bridgeCommandId = $command->id;
        $this->em->flush();
    }
}
