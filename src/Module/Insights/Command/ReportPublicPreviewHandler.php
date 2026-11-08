<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Insights\Service\PublicPreviewUsage;
use App\Module\Insights\Service\PublicPreviewUsageReader;
use App\Observability\SentryDsnStatus;
use Sentry\Unit;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Sends the gauges of the kept preview images to Sentry: how many there are, their bytes, and the size of the database.
 * The worker sends buffered metrics only when its command ends, so the handler flushes the buffer itself.
 */
final readonly class ReportPublicPreviewHandler
{
    public function __construct(
        private PublicPreviewUsageReader $reader,

        #[Autowire('%env(default::SENTRY_DSN)%')]
        private ?string $dsn,
    ) {
    }

    /** @return PublicPreviewUsage|null null when no usable DSN is set */
    public function __invoke(ReportPublicPreviewCommand $command): ?PublicPreviewUsage
    {
        if (SentryDsnStatus::Valid !== SentryDsnStatus::of($this->dsn)) {
            return null;
        }

        $usage = $this->reader->read();
        $metrics = \Sentry\traceMetrics();
        $metrics->gauge('public_preview.count', $usage->images);
        $metrics->gauge('public_preview.bytes', $usage->imageBytes, [], Unit::byte());
        $metrics->gauge('database.bytes', $usage->databaseBytes, [], Unit::byte());
        $metrics->flush();

        return $usage;
    }
}
