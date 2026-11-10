<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricRange;
use App\Module\Bridge\Metric\MetricUnit;
use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Workflow\Contract\TemplateMetrics;

/** Which kinds of data a saved graph reads, so a write refreshes only the graphs that it can change. */
final readonly class InsightsDataKinds
{
    public function __construct(
        private TemplateMetrics $templateMetrics,
    ) {
    }

    /**
     * The kinds that the metric of a graph reads, or the union over the rows of a formula graph.
     *
     * @return list<InsightsDataKind>
     */
    public function reads(InsightsSavedGraph $graph): array
    {
        $kinds = [];
        foreach ($graph->metricKeys() as $key) {
            foreach ($this->readsOf($graph, $key) as $kind) {
                $kinds[$kind->value] = $kind;
            }
        }

        return array_values($kinds);
    }

    /** Whether the sweep keeps the graph fresh: a rolling range, or a template metric that no data event covers. */
    public function sweeps(InsightsSavedGraph $graph): bool
    {
        return MetricRange::All !== $graph->range
            || array_any($graph->metricKeys(), static fn (string $key): bool => null === Metric::tryFrom(explode(':', $key, 2)[0]));
    }

    /** @return list<InsightsDataKind> */
    private function readsOf(InsightsSavedGraph $graph, string $key): array
    {
        $metric = Metric::tryFrom(explode(':', $key, 2)[0]);
        if (null === $metric) {
            return null !== $this->templateMetrics->forProject($graph->project->requireId())->metric($key)
                ? [InsightsDataKind::Cards]
                : [InsightsDataKind::Runs, InsightsDataKind::BucketTimes, InsightsDataKind::Cards, InsightsDataKind::Readings];
        }
        if (MetricUnit::Reading === $graph->unit) {
            return [InsightsDataKind::Readings];
        }

        $kinds = [InsightsDataKind::Runs];
        if (MetricUnit::Card === $graph->unit) {
            $kinds[] = InsightsDataKind::Cards;
        }
        if ($metric->isBucketEach() || \in_array($metric, [Metric::BucketTime, Metric::BucketTimeAll, Metric::BucketCalls], true)) {
            $kinds[] = InsightsDataKind::BucketTimes;
        }

        return $kinds;
    }

    /** @return list<string> the keys of the built-in metrics */
    public function builtInMetrics(): array
    {
        return array_map(static fn (Metric $metric): string => $metric->value, Metric::cases());
    }

    /** A shared experiment reads these kinds, and its own setup. */
    public function experimentReads(InsightsDataKind $kind): bool
    {
        return \in_array($kind, [InsightsDataKind::Runs, InsightsDataKind::BucketTimes, InsightsDataKind::Cards], true);
    }
}
