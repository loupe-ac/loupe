<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Insights\Entity\InsightsSavedGraph;

final readonly class SetLegendSavedGraphCommand
{
    public function __construct(
        public InsightsSavedGraph $graph,
        public bool $showLegend,
    ) {
    }
}
