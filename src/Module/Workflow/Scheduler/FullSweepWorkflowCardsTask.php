<?php

declare(strict_types=1);

namespace App\Module\Workflow\Scheduler;

use App\Module\Workflow\Command\FullSweepWorkflowCardsCommand;
use App\Module\Workflow\Command\FullSweepWorkflowCardsHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/**
 * Each hour, starts a full sweep when the last one is older than `workflow.full_sweep_interval_hours`.
 * `app:full-sweep-workflow-cards` is the manual backstop.
 */
#[AsCronTask('%app.workflow.full_sweep_schedule%')]
final readonly class FullSweepWorkflowCardsTask
{
    public function __construct(
        private FullSweepWorkflowCardsHandler $fullSweep,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(): void
    {
        $cards = ($this->fullSweep)(new FullSweepWorkflowCardsCommand());
        if ($cards > 0) {
            $this->logger->info('workflow.full_sweep_started', ['cards' => $cards]);
        }
    }
}
