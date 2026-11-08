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

    /** @return list<InsightsDataKind> */
    public function reads(InsightsSavedGraph $graph): array
    {
        $key = explode(':', $graph->metric, 2)[0];
        $metric = Metric::tryFrom($key);
        if (null === $metric) {
            return null !== $this->templateMetrics->forProject($graph->project->requireId())->metric($graph->metric)
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

    /** Whether the sweep keeps the graph fresh: a rolling range, or a template metric that no data event covers. */
    public function sweeps(InsightsSavedGraph $graph): bool
    {
        return MetricRange::All !== $graph->range || null === Metric::tryFrom(explode(':', $graph->metric, 2)[0]);
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
