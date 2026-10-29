<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricKey;
use App\Module\Bridge\Metric\MetricRowSource;
use App\Module\Bridge\Metric\MetricShape;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricUnit;
use App\Module\Bridge\Service\BucketRule;
use App\Module\Insights\Entity\InsightsBucketRule;
use App\Module\Insights\Repository\InsightsBucketRuleRepository;
use App\Module\Insights\View\MetricsQuery;
use Psr\Clock\ClockInterface;

final readonly class ShowBucketSummaryHandler
{
    public function __construct(
        private InsightsBucketRuleRepository $insightsBucketRules,
        private MetricRowSource $metricRows,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ShowBucketSummaryCommand $command): ShowBucketSummaryView
    {
        $bucketTimes = $this->metricRows->bucketTimes($command->project, $command->range->startFrom($this->clock->now()));

        $named = array_map(static fn (InsightsBucketRule $rule): string => $rule->bucket, $this->insightsBucketRules->findOrdered($command->project));
        $named[] = BucketRule::FALLBACK;
        $named = array_values(array_unique($named));
        $timed = [];
        foreach ($bucketTimes->times as $runTimes) {
            foreach ($runTimes as $name => $ms) {
                if (0 < $ms) {
                    $timed[(string) $name] = true;
                }
            }
        }
        $others = array_values(array_diff(array_map(strval(...), array_keys($timed)), $named));
        sort($others, \SORT_STRING);

        $figures = [];
        foreach ([...$named, ...$others] as $name) {
            // A run with data and no time in the bucket spent 0 ms there.
            $values = array_values(array_map(static fn (array $runTimes): int => $runTimes[$name] ?? 0, $bucketTimes->times));
            $figures[$name] = [
                MetricStatistic::Sum->of($values) ?? 0,
                MetricStatistic::Median->of($values),
                self::sumOf($bucketTimes->subagentFigures, $name, 'msWithSubagents'),
                self::sumOf($bucketTimes->subagentFigures, $name, 'calls'),
            ];
        }
        $grandTotal = array_sum(array_column($figures, 0));

        $rows = [];
        foreach ($figures as $name => [$total, $median, $msWithSubagents, $calls]) {
            $name = (string) $name;
            $rows[] = new BucketSummaryRow(
                $name,
                $total,
                $median,
                $msWithSubagents,
                $calls,
                0 < $grandTotal ? $total / $grandTotal : 0.0,
                new MetricsQuery(unit: MetricUnit::Run, metric: new MetricShape(new MetricKey(Metric::BucketTime, $name)), statistic: MetricStatistic::Median, range: $command->range)->routeParams(),
            );
        }

        return new ShowBucketSummaryView($command->project, $command->range, $rows, \count($bucketTimes->times), $bucketTimes->runs);
    }

    /**
     * @param array<string, array<int|string, array{msWithSubagents: int|null, calls: int|null}>> $figures
     * @param 'msWithSubagents'|'calls'                                                           $column
     */
    private static function sumOf(array $figures, string $bucket, string $column): ?int
    {
        $values = [];
        foreach ($figures as $runFigures) {
            // A run with a row not yet recomputed has no value for the figure, and a bucket it lacks is 0.
            $known = array_column($runFigures, $column);
            if (\in_array(null, $known, true)) {
                continue;
            }
            $values[] = $runFigures[$bucket][$column] ?? 0;
        }

        return [] === $values ? null : array_sum($values);
    }
}
