<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Repository\InsightsSavedGraphRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/** Orders a write to one saved graph against every other write to the graphs of its project. */
final readonly class SavedGraphLocker
{
    public function __construct(
        private EntityManagerInterface $em,
        private InsightsSavedGraphRepository $insightsSavedGraphs,
    ) {
    }

    /**
     * Locks the project row, then reads the fields that a write can change again, so a write sees a change
     * from another tab. Entity refresh is not possible, because the entity has read-only fields.
     * Call it inside a transaction. It throws nothing, because a thrown exception closes the entity manager.
     *
     * @return bool false when the graph row is gone
     */
    public function lock(InsightsSavedGraph $graph): bool
    {
        $this->em->lock($graph->project, LockMode::PESSIMISTIC_WRITE);
        $state = $this->insightsSavedGraphs->findStateOf($graph);
        if (null === $state) {
            return false;
        }
        $graph->name = $state['name'];
        $graph->metric = $state['metric'];
        $graph->unit = $state['unit'];
        $graph->statistic = $state['statistic'];
        $graph->group = $state['metricGroup'];
        $graph->range = $state['range'];
        $graph->bucket = $state['bucket'];
        $graph->compare = $state['compare'];
        $graph->chartType = $state['chartType'];
        $graph->width = $state['width'];
        $graph->showLegend = $state['showLegend'];
        $graph->position = $state['position'];
        $graph->removedAt = $state['removedAt'];

        return true;
    }

    /** @return bool false when the graph row is gone or the graph is removed */
    public function lockActive(InsightsSavedGraph $graph): bool
    {
        return $this->lock($graph) && null === $graph->removedAt;
    }
}
