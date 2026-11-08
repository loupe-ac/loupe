<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Messenger\RefreshInsightsItem;
use App\Module\Insights\Messenger\RefreshProjectInsights;
use App\Module\Project\Entity\Project;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;
use Symfony\Component\Messenger\Stamp\DelayStamp;

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
        private InsightsRefreshSettings $settings,
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

    /** Asks for the sweep refresh of a project at once. The sweep runs each minute, so a queued message absorbs the next. */
    public function sweep(string $projectId): void
    {
        $this->bus->dispatch(
            new RefreshProjectInsights($projectId, InsightsDataKind::Sweep->value),
            [new DeduplicateStamp('insights:'.$projectId.':'.InsightsDataKind::Sweep->value, self::DEDUPLICATE_TTL, true)],
        );
    }

    /** Asks for a refresh of every item of a project that a view found dormant. */
    public function wake(string $projectId): void
    {
        $this->bus->dispatch(
            new RefreshProjectInsights($projectId, InsightsDataKind::Wake->value),
            [new DeduplicateStamp('insights:'.$projectId.':'.InsightsDataKind::Wake->value, self::DEDUPLICATE_TTL, true)],
        );
    }

    /**
     * Asks for a refresh of the items of a project that read one kind of data, after the grouping delay. A second request
     * for the same project and kind is dropped while the first one waits in the queue. The lock ends when the worker
     * receives the message, so data that arrives during a compute queues a new refresh.
     */
    public function projectKind(string $projectId, InsightsDataKind $kind, ?string $experiment = null): void
    {
        $delay = $this->settings->refreshDelaySeconds();
        $key = 'insights:'.$projectId.':'.$kind->value.(null === $experiment ? '' : ':'.hash('sha256', $experiment));
        $this->bus->dispatch(
            new RefreshProjectInsights($projectId, $kind->value, $experiment),
            [new DelayStamp($delay * 1000), new DeduplicateStamp($key, $delay + self::DEDUPLICATE_TTL, true)],
        );
    }
}
