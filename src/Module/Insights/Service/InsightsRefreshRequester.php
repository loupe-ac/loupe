<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Messenger\RefreshInsightsItem;
use App\Module\Project\Entity\Project;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;

/**
 * Asks the insights worker to compute one item at once. A second request for the same item is dropped while the first
 * one waits in the queue, so a burst of clicks or views costs one compute.
 */
final readonly class InsightsRefreshRequester
{
    /** Seconds. */
    private const int DEDUPLICATE_TTL = 300;

    public function __construct(
        private MessageBusInterface $bus,
    ) {
    }

    public function graph(InsightsSavedGraph $graph): void
    {
        $graphId = (string) $graph->id;
        $this->bus->dispatch(
            new RefreshInsightsItem((string) $graph->project->requireId(), $graphId, null),
            [new DeduplicateStamp('insights_item:'.$graphId, self::DEDUPLICATE_TTL, true)],
        );
    }

    public function experiment(Project $project, string $experiment): void
    {
        $projectId = (string) $project->requireId();
        $this->bus->dispatch(
            new RefreshInsightsItem($projectId, null, $experiment),
            [new DeduplicateStamp('insights_item:'.$projectId.':'.hash('sha256', $experiment), self::DEDUPLICATE_TTL, true)],
        );
    }
}
