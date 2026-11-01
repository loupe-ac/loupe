<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Insights\Entity\InsightsSavedGraph;

final readonly class MoveSavedGraphCommand
{
    public function __construct(
        public InsightsSavedGraph $graph,
        /** Places to move among the graphs on show. Negative moves up. A move past either end stops at the end. */
        public int $steps,
    ) {
    }
}
