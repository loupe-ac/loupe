<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Entity\SavedGraphWidth;

final readonly class ResizeSavedGraphCommand
{
    public function __construct(
        public InsightsSavedGraph $graph,
        public SavedGraphWidth $width,
    ) {
    }
}
