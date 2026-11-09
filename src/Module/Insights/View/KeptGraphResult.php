<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

use App\Module\Bridge\Metric\MetricSeries;
use App\Module\Bridge\Metric\MetricWindow;

/**
 * What a graph computed, with no language in it: the query, the series in drawing order, the bridge names and
 * the two windows. The page builds the title, the labels and the layout from it in the language of the viewer.
 */
final readonly class KeptGraphResult
{
    /**
     * @param list<MetricSeries>    $series      the series in the order of the labels and the layout
     * @param array<string, string> $groupLabels the bridge name of each bridge group, empty for any other group
     */
    public function __construct(
        /** The clock reading both windows count back from. */
        public \DateTimeImmutable $computedAt,
        public MetricsQuery $query,
        public array $series,
        public array $groupLabels,
        public MetricWindow $current,
        /** Null when the graph does not compare. */
        public ?MetricWindow $previous,
    ) {
    }

    /** Whether the graph draws as a line and the last point of a series sits in a bucket that is still open at the time of the read. */
    public function drawsOpenLine(\DateTimeImmutable $at): bool
    {
        $query = $this->query;
        if (MetricChartType::Line !== MetricChartRules::resolve($query->chart, $query->metric, $query->statistic, \count($this->series))->type) {
            return false;
        }
        foreach ($this->series as $series) {
            $last = $series->points[\count($series->points) - 1] ?? null;
            if (null !== $last?->start && null !== $last->value && $query->bucket->isOpen($last->start, $at)) {
                return true;
            }
        }

        return false;
    }
}
