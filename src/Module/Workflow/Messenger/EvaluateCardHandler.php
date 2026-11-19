<?php

declare(strict_types=1);

namespace App\Module\Workflow\Messenger;

use App\Module\Workflow\Command\EvaluateWorkflowCardCommand;
use App\Module\Workflow\Command\EvaluateWorkflowCardHandler;
use App\Module\Workflow\Contract\PersonWaitingCardEvaluated;
use App\Module\Workflow\Repository\WorkflowCardChangeRepository;
use App\Module\Workflow\Repository\WorkflowQueuedEvaluationRepository;
use App\Module\Workflow\Service\EvaluationTrigger;
use Doctrine\DBAL\Exception\DeadlockException;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class EvaluateCardHandler
{
    private const string SERIALIZATION_FAILURE = '40001';

    public function __construct(
        private EvaluateWorkflowCardHandler $evaluateWorkflowCard,
        private WorkflowQueuedEvaluationRepository $workflowQueuedEvaluations,
        private WorkflowCardChangeRepository $workflowCardChanges,
        private EventDispatcherInterface $events,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(EvaluateCard $message): void
    {
        $cardId = Uuid::fromString($message->cardId);
        // Release before the evaluation, so a trigger that arrives during it queues one more. It runs with the flag off too, to clear a row claimed before a switch-off.
        $this->workflowQueuedEvaluations->release($cardId);
        // Its own statement, before the check. A write that commits after it marks the card again.
        $marked = $this->workflowCardChanges->clear($cardId);
        $reportMiss = $message->fullSweep && !$marked;

        try {
            ($this->evaluateWorkflowCard)(new EvaluateWorkflowCardCommand($cardId, $reportMiss));
        } catch (DeadlockException $e) {
            if (!$reportMiss || self::SERIALIZATION_FAILURE !== $e->getSQLState()) {
                throw $e;
            }
            // A writer committed during the check, and the next check reads that commit. The bus writes through DBAL, so the closed entity manager does not stop it.
            $this->bus->dispatch($message, [new TransportNamesStamp([EvaluationTrigger::LOW_PRIORITY_TRANSPORT])]);
            $this->logger->info('workflow.sweep_check_requeued', ['cardId' => $message->cardId]);

            return;
        }

        if ($message->personWaiting) {
            $this->events->dispatch(new PersonWaitingCardEvaluated($message->cardId));
        }
    }
}
