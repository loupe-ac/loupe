<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\View\MetricsQuery;

/** A stored graph and the Metrics controls that draw it. */
final readonly class SavedGraphResult
{
    public function __construct(
        public InsightsSavedGraph $graph,
        public MetricsQuery $query,
    ) {
    }
}
