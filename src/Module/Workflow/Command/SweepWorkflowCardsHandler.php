<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

use App\Module\Workflow\Repository\WorkflowBindingRepository;
use App\Module\Workflow\Service\EvaluationTrigger;
use App\Module\Workflow\Service\WorkflowSweepRecord;
use App\Service\BuildIdentity;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * The backstop for a card change that no event reported. Answers how many evaluations it queued, and queues none while the previous sweep still waits.
 * The first sweep under a new build is the deploy pass: its checks report each change that the marks missed.
 */
final readonly class SweepWorkflowCardsHandler
{
    public function __construct(
        private WorkflowBindingRepository $workflowBindings,
        private EvaluationTrigger $trigger,
        private WorkflowSweepRecord $record,
        private BuildIdentity $build,
        private ClockInterface $clock,

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
        $version = $this->build->version;
        $deployPass = null !== $version && $this->record->build() !== $version;
        $this->trigger->inBackground($cardIds, self::class, null, $deployPass);
        if ($deployPass) {
            $this->record->recordBuild($version);
            $this->record->recordFull(\DateTimeImmutable::createFromInterface($this->clock->now()));
        }

        return \count($cardIds);
    }
}
