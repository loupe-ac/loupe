<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Insights\Repository\InsightsSavedGraphRepository;
use App\Module\Insights\Service\DashboardGraphResults;
use App\Module\Insights\Service\SavedGraphCap;
use App\Module\Insights\Service\SavedGraphHealth;
use App\Module\Insights\View\DashboardTileView;
use App\Module\Insights\View\DashboardView;
use Psr\Clock\ClockInterface;

/** Reads the Dashboard. It computes nothing: a tile with no kept result waits for its lazy frame. */
final readonly class ShowDashboardHandler
{
    public function __construct(
        private InsightsSavedGraphRepository $insightsSavedGraphs,
        private SavedGraphHealth $health,
        private DashboardGraphResults $results,
        private SavedGraphCap $cap,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ShowDashboardCommand $command): DashboardView
    {
        $project = $command->project;
        $graphs = $this->insightsSavedGraphs->findActive($project);
        $broken = $this->health->check($project, $graphs);

        $tiles = [];
        $last = \count($graphs) - 1;
        foreach ($graphs as $index => $graph) {
            $problem = $broken[(string) $graph->id] ?? null;
            $tiles[] = new DashboardTileView($graph, $problem, null === $problem ? $this->results->get($project, $graph) : null, $index > 0, $index < $last);
        }

        $computed = array_values(array_filter(array_map(static fn (DashboardTileView $tile): ?\DateTimeImmutable => $tile->result?->computedAt, $tiles)));
        $removed = null;
        if (null !== $command->removedGraphId) {
            $candidate = $this->insightsSavedGraphs->findOneByIdAndProjectId($command->removedGraphId, (string) $project->requireId());
            $removed = null !== $candidate && null !== $candidate->removedAt ? $candidate : null;
        }

        return new DashboardView(
            $project,
            $tiles,
            $removed,
            $this->cap->isReached($project),
            $this->cap->limit(),
            [] === $computed ? null : min($computed),
            \DateTimeImmutable::createFromInterface($this->clock->now()),
        );
    }
}
