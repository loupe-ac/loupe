<?php

declare(strict_types=1);

namespace App\Observability\Scheduler;

use App\Observability\Command\ReportQueueDepthCommand;
use App\Observability\Command\ReportQueueDepthHandler;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/**
 * Sends the queue gauges to Sentry once a minute. `app:report-queue-depth` is
 * the manual backstop.
 */
#[AsCronTask('%app.observability.queue_depth_schedule%')]
final readonly class ReportQueueDepthTask
{
    public function __construct(
        private ReportQueueDepthHandler $reportQueueDepth,
    ) {
    }

    public function __invoke(): void
    {
        ($this->reportQueueDepth)(new ReportQueueDepthCommand());
    }
}
