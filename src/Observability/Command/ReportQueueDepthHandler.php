<?php

declare(strict_types=1);

namespace App\Observability\Command;

use App\Observability\QueueDepth;
use App\Observability\QueueDepthReader;
use App\Observability\SentryDsnStatus;
use Sentry\Unit;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Sends the waiting count and the oldest waiting age of each queue to Sentry.
 *
 * The worker sends buffered metrics only when its command ends, up to an hour
 * later, so the handler flushes the buffer itself.
 */
final readonly class ReportQueueDepthHandler
{
    public function __construct(
        private QueueDepthReader $reader,

        #[Autowire('%env(default::SENTRY_DSN)%')]
        private ?string $dsn,
    ) {
    }

    /**
     * @return list<QueueDepth> the depths sent, empty when no usable DSN is set
     */
    public function __invoke(ReportQueueDepthCommand $command): array
    {
        if (SentryDsnStatus::Valid !== SentryDsnStatus::of($this->dsn)) {
            return [];
        }

        $metrics = \Sentry\traceMetrics();
        $depths = $this->reader->read();

        foreach ($depths as $depth) {
            $attributes = ['queue_name' => $depth->queueName, 'transport' => $depth->transport];
            $metrics->gauge('messenger.queue.pending', $depth->pending, $attributes);
            $metrics->gauge('messenger.queue.oldest_age', $depth->oldestAgeSeconds, $attributes, Unit::second());
        }

        $metrics->flush();

        return $depths;
    }
}
