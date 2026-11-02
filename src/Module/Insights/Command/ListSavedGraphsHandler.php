<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Insights\Repository\InsightsSavedGraphRepository;
use App\Module\Insights\Service\SavedGraphCap;
use App\Module\Insights\Service\SavedGraphHealth;
use Psr\Clock\ClockInterface;

/** Reads the graphs of the Dashboard with no chart result, because a list draws no chart. */
final readonly class ListSavedGraphsHandler
{
    public const int MAX_REMOVED = 20;

    public function __construct(
        private InsightsSavedGraphRepository $insightsSavedGraphs,
        private SavedGraphHealth $health,
        private SavedGraphCap $cap,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ListSavedGraphsCommand $command): ListSavedGraphsView
    {
        $project = $command->project;
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $graphs = $this->insightsSavedGraphs->findActive($project);
        $removed = $this->insightsSavedGraphs->findRestorable($project, $now, self::MAX_REMOVED);

        return new ListSavedGraphsView(
            $graphs,
            $this->health->check($project, $graphs),
            $this->cap->limit(),
            $removed,
            max(0, $this->insightsSavedGraphs->countRestorable($project, $now) - \count($removed)),
        );
    }
}
