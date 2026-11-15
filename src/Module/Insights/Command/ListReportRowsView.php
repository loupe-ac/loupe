<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

final readonly class ListReportRowsView
{
    /** @param list<AnalysisDetailView> $rows the asked analyses that still exist, newest first */
    public function __construct(
        public array $rows,
        public int $newer,
    ) {
    }
}
