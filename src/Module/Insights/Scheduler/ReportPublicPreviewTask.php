<?php

declare(strict_types=1);

namespace App\Module\Insights\Scheduler;

use App\Module\Insights\Command\ReportPublicPreviewCommand;
use App\Module\Insights\Command\ReportPublicPreviewHandler;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/** Sends the preview image gauges to Sentry every five minutes, so the move out of Postgres has data behind it. */
#[AsCronTask('*/5 * * * *')]
final readonly class ReportPublicPreviewTask
{
    public function __construct(
        private ReportPublicPreviewHandler $reportPublicPreview,
    ) {
    }

    public function __invoke(): void
    {
        ($this->reportPublicPreview)(new ReportPublicPreviewCommand());
    }
}
