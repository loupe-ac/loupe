<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\View\BrokenGraph;

/** The saved graphs of a project in Dashboard order, with the newest graphs that Undo can still bring back. */
final readonly class ListSavedGraphsView
{
    public function __construct(
        /** @var list<InsightsSavedGraph> the graphs on show */
        public array $graphs,
        /** @var array<string, BrokenGraph> the broken graphs on show, by graph id */
        public array $broken,
        public int $limit,
        /** @var list<InsightsSavedGraph> the newest removed graphs inside the Undo window */
        public array $removed,
        /** The restorable graphs that $removed leaves out. */
        public int $removedOmitted,
    ) {
    }
}
