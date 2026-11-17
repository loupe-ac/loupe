<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Bridge\Metric\MetricFacts;
use App\Module\Insights\View\MetricsQuery;
use App\Module\Project\Entity\Project;

final readonly class ShowMetricsCommand
{
    public function __construct(
        public Project $project,
        public MetricsQuery $query,
        /** The clock reading both periods count back from. Null reads the clock once. */
        public ?\DateTimeImmutable $now = null,
        /** The facts a refresh loaded once for all its graphs. Null makes the query load its own. */
        public ?MetricFacts $facts = null,
    ) {
    }
}
