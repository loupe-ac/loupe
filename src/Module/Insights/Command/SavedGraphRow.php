<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\View\BrokenGraph;

/** A saved graph with its place among the graphs on show and the part of it that is gone. */
final readonly class SavedGraphRow
{
    public function __construct(
        public InsightsSavedGraph $graph,
        /** Counted from 0. Null for a removed graph. */
        public ?int $position,
        public ?BrokenGraph $broken,
    ) {
    }
}
