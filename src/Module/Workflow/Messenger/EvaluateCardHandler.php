<?php

declare(strict_types=1);

namespace App\Module\Workflow\Messenger;

use App\Module\Workflow\Command\EvaluateWorkflowCardCommand;
use App\Module\Workflow\Command\EvaluateWorkflowCardHandler;
use App\Module\Workflow\Contract\PersonWaitingCardEvaluated;
use App\Module\Workflow\Repository\WorkflowQueuedEvaluationRepository;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class EvaluateCardHandler
{
    public function __construct(
        private EvaluateWorkflowCardHandler $evaluateWorkflowCard,
        private WorkflowQueuedEvaluationRepository $workflowQueuedEvaluations,
        private EventDispatcherInterface $events,
    ) {
    }

    public function __invoke(EvaluateCard $message): void
    {
        $cardId = Uuid::fromString($message->cardId);
        // Release before the evaluation, so a trigger that arrives during it queues one more. It runs with the flag off too, to clear a row claimed before a switch-off.
        $this->workflowQueuedEvaluations->release($cardId);

        ($this->evaluateWorkflowCard)(new EvaluateWorkflowCardCommand($cardId));

        if ($message->personWaiting) {
            $this->events->dispatch(new PersonWaitingCardEvaluated($message->cardId));
        }
    }
}
