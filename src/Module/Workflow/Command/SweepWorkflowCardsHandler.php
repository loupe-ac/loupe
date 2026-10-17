<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

use App\Module\Workflow\Repository\WorkflowBindingRepository;
use App\Module\Workflow\Service\EvaluationTrigger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/** The backstop for a card change that no event reported. Answers how many evaluations it queued, and queues none while the previous sweep still waits. */
final readonly class SweepWorkflowCardsHandler
{
    public function __construct(
        private WorkflowBindingRepository $workflowBindings,
        private EvaluationTrigger $trigger,

        #[Autowire(service: 'messenger.transport.card_sweep')]
        private TransportInterface $lowPriorityTransport,
    ) {
    }

    public function __invoke(SweepWorkflowCardsCommand $command): int
    {
        if ($this->lowPriorityTransport instanceof MessageCountAwareInterface && $this->lowPriorityTransport->getMessageCount() > 0) {
            return 0;
        }

        $cardIds = $this->workflowBindings->findOpenBoundCardIds();
        $this->trigger->inBackground($cardIds);

        return \count($cardIds);
    }
}
