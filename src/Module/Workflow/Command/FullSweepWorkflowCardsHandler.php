<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

use App\Module\Workflow\Repository\WorkflowBindingRepository;
use App\Module\Workflow\Service\EvaluationTrigger;
use App\Module\Workflow\Service\WorkflowSweepRecord;
use App\Module\Workflow\Service\WorkflowSweepSettings;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Queues a check of every open card, and reports each change that the marks missed.
 * It starts only when the last full sweep is older than the flag interval, and it waits while the previous sweep still waits.
 * Answers how many checks it queued.
 */
final readonly class FullSweepWorkflowCardsHandler
{
    public function __construct(
        private WorkflowBindingRepository $workflowBindings,
        private EvaluationTrigger $trigger,
        private WorkflowSweepRecord $record,
        private WorkflowSweepSettings $settings,
        private ClockInterface $clock,

        #[Autowire(service: 'messenger.transport.card_sweep')]
        private TransportInterface $lowPriorityTransport,
    ) {
    }

    public function __invoke(FullSweepWorkflowCardsCommand $command): int
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        if (!$command->force && !$this->due($now)) {
            return 0;
        }
        if ($this->lowPriorityTransport instanceof MessageCountAwareInterface && $this->lowPriorityTransport->getMessageCount() > 0) {
            return 0;
        }

        $cardIds = $this->workflowBindings->findOpenBoundCardIds();
        $this->trigger->inBackground($cardIds, self::class, null, true);
        $this->record->recordFull($now);

        return \count($cardIds);
    }

    private function due(\DateTimeImmutable $now): bool
    {
        $last = $this->record->lastFullAt();

        return null === $last || $last <= $now->modify(\sprintf('-%d hours', $this->settings->fullSweepIntervalHours()));
    }
}
