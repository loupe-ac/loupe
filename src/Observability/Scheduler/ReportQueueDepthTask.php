<?php

declare(strict_types=1);

namespace App\Observability\Scheduler;

use App\Observability\QueueDepthReader;
use App\Observability\SentryDsnStatus;
use Sentry\Unit;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/**
 * Sends the waiting count and the oldest waiting age of each queue to Sentry.
 *
 * The worker sends buffered metrics only when its command ends, up to an hour
 * later, so the task flushes the buffer itself.
 */
#[AsCronTask('%app.observability.queue_depth_schedule%')]
final readonly class ReportQueueDepthTask
{
    public function __construct(
        private QueueDepthReader $reader,

        #[Autowire('%env(default::SENTRY_DSN)%')]
        private ?string $dsn,
    ) {
    }

    public function __invoke(): void
    {
        if (SentryDsnStatus::Valid !== SentryDsnStatus::of($this->dsn)) {
            return;
        }

        $metrics = \Sentry\traceMetrics();

        foreach ($this->reader->read() as $depth) {
            $attributes = ['queue_name' => $depth->queueName, 'transport' => $depth->transport];
            $metrics->gauge('messenger.queue.pending', $depth->pending, $attributes);
            $metrics->gauge('messenger.queue.oldest_age', $depth->oldestAgeSeconds, $attributes, Unit::second());
        }

        $metrics->flush();
    }
}
