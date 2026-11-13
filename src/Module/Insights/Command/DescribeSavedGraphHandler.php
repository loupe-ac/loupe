<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Insights\Repository\InsightsSavedGraphRepository;
use App\Module\Insights\Service\SavedGraphHealth;

/** Reads one saved graph with its place on the Dashboard and the part of it that is gone. */
final readonly class DescribeSavedGraphHandler
{
    public function __construct(
        private InsightsSavedGraphRepository $insightsSavedGraphs,
        private SavedGraphHealth $health,
    ) {
    }

    public function __invoke(DescribeSavedGraphCommand $command): SavedGraphRow
    {
        $graph = $command->graph;
        if (null !== $graph->removedAt) {
            return new SavedGraphRow($graph, null, null);
        }

        $onShow = $this->insightsSavedGraphs->findActive($graph->project);
        $position = array_find_key($onShow, static fn ($other): bool => $other->id?->equals($graph->id) ?? false);

        return new SavedGraphRow($graph, $position, $this->health->check($graph->project, [$graph])[(string) $graph->id] ?? null);
    }
}
