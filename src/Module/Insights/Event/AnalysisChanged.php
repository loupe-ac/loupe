<?php

declare(strict_types=1);

namespace App\Module\Insights\Event;

/** An analysis, its proposals or the analysis settings of a project changed. Dispatched after the flush, with ids only. */
final readonly class AnalysisChanged
{
    public function __construct(
        public string $projectId,
        /** Null when the change names no one analysis. A settings change names AnalyticsArea::SETTINGS_ID. */
        public ?string $analysisId,
    ) {
    }
}
