<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Exception\DomainErrors;
use App\Module\Bridge\Experiment\Confidence;
use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricCompare;
use App\Module\Bridge\Metric\MetricComparer;
use App\Module\Bridge\Metric\MetricComparison;
use App\Module\Bridge\Metric\MetricPoint;
use App\Module\Bridge\Metric\MetricRow;
use App\Module\Bridge\Metric\MetricRowSource;
use App\Module\Bridge\Metric\MetricSeries;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricWindow;
use Psr\Clock\ClockInterface;

final readonly class MetricQueryHandler
{
    public const string UNIT_NOT_ALLOWED = 'bridge.metric.error.unit_not_allowed';

    public const string STATISTIC_NOT_ALLOWED = 'bridge.metric.error.statistic_not_allowed';

    public const string GROUP_NOT_ALLOWED = 'bridge.metric.error.group_not_allowed';

    public const string BUCKET_NOT_ALLOWED = 'bridge.metric.error.bucket_not_allowed';

    public const string COMPARE_NOT_ALLOWED = 'bridge.metric.error.compare_not_allowed';

    public function __construct(
        private MetricRowSource $rows,
        private ClockInterface $clock,
        private MetricComparer $comparer = new MetricComparer(),
    ) {
    }

    public function __invoke(MetricQueryCommand $command): MetricQueryView
    {
        $metric = $command->metric;
        $errors = [];
        if (!\in_array($command->unit, $metric->units(), true)) {
            $errors['unit'] = self::UNIT_NOT_ALLOWED;
        }
        if (!\in_array($command->statistic, $metric->statistics(), true)) {
            $errors['statistic'] = self::STATISTIC_NOT_ALLOWED;
        }
        if (!\in_array($command->group, $metric->groups(), true)) {
            $errors['group'] = self::GROUP_NOT_ALLOWED;
        }
        if (!\in_array($command->bucket, $command->range->allowedBuckets(), true)) {
            $errors['bucket'] = self::BUCKET_NOT_ALLOWED;
        }
        if (MetricCompare::Previous === $command->compare && null === $command->range->length()) {
            $errors['compare'] = self::COMPARE_NOT_ALLOWED;
        }
        if ([] !== $errors) {
            throw new DomainErrors($errors);
        }

        // Both periods count back from one clock reading.
        $now = $command->now ?? $this->clock->now();
        $from = $command->range->startFrom($now);
        $rows = $this->rows->rows($command->project, $command->unit, $metric, $command->group, $from, $now);

        $previousWindow = null;
        $previousRows = [];
        if (MetricCompare::Previous === $command->compare) {
            $length = $command->range->length() ?? throw new \LogicException('A range with a length starts at a time.');
            $previousFrom = ($from ?? throw new \LogicException('A range with a length starts at a time.'))->sub($length);
            $previousWindow = new MetricWindow($previousFrom, $from);
            // The previous rows move forward by the length, so they fall into the same buckets as the current rows.
            $previousRows = array_map(
                static fn (MetricRow $row): MetricRow => new MetricRow($row->unitId, $row->cardNumber, $row->group, $row->time->add($length), $row->value),
                $this->rows->rows($command->project, $command->unit, $metric, $command->group, $previousFrom, $from),
            );
        }

        $byGroup = self::byGroup($rows);
        $previousByGroup = self::byGroup($previousRows);
        // A line that only the previous period holds is still a line of the graph.
        foreach ($previousByGroup as $key => $part) {
            $byGroup[$key] ??= ['group' => $part['group'], 'rows' => []];
        }
        // The empty key sorts first, so the series with no group moves to the end.
        ksort($byGroup, \SORT_STRING);
        if (isset($byGroup[''])) {
            $none = $byGroup[''];
            unset($byGroup['']);
            $byGroup[''] = $none;
        }

        $compare = null !== $previousWindow;
        // Every line that has a sign widens every range, so a graph with many lines draws a sign by chance more rarely.
        $confidence = Confidence::forComparisons(MetricComparer::hasSign($command->statistic) ? \count($byGroup) : 1);
        $seed = \sprintf('%s|%s|%s', $command->project->requireId()->toRfc4122(), $metric->key->key(), $command->statistic->value);

        $series = [];
        foreach ($byGroup as $key => $group) {
            $previousOfGroup = $previousByGroup[$key]['rows'] ?? [];
            $series[] = self::series(
                $group['group'],
                $group['rows'],
                $command->statistic,
                $command->bucket,
                $compare ? $previousOfGroup : null,
                $compare ? $this->comparer->compare($command->statistic, $metric->valueType(), $command->unit, $group['rows'], $previousOfGroup, $confidence, $seed.'|'.$key) : null,
            );
        }

        return new MetricQueryView($command, $series, new MetricWindow($from, $now), $previousWindow);
    }

    /**
     * @param list<MetricRow> $rows
     *
     * @return array<string, array{group: ?string, rows: list<MetricRow>}>
     */
    private static function byGroup(array $rows): array
    {
        $byGroup = [];
        foreach ($rows as $row) {
            $key = null === $row->group ? '' : 'key:'.$row->group;
            $byGroup[$key] ??= ['group' => $row->group, 'rows' => []];
            $byGroup[$key]['rows'][] = $row;
        }

        return $byGroup;
    }

    /**
     * @param list<MetricRow>  $rows
     * @param ?list<MetricRow> $previousRows the previous period already moved onto the current dates, null when the query does not compare
     */
    private static function series(?string $group, array $rows, MetricStatistic $statistic, MetricBucket $bucket, ?array $previousRows, ?MetricComparison $comparison): MetricSeries
    {
        usort($rows, static fn (MetricRow $a, MetricRow $b): int => [$b->time, (string) $b->unitId] <=> [$a->time, (string) $a->unitId]);

        return new MetricSeries(
            $group,
            self::points($rows, $statistic, $bucket),
            self::point(null, $rows, $statistic),
            $rows,
            $comparison,
            null === $previousRows ? [] : self::points($previousRows, $statistic, $bucket),
        );
    }

    /**
     * @param list<MetricRow> $rows
     *
     * @return list<MetricPoint>
     */
    private static function points(array $rows, MetricStatistic $statistic, MetricBucket $bucket): array
    {
        /** @var array<int, array{start: \DateTimeImmutable, rows: list<MetricRow>}> $buckets */
        $buckets = [];
        foreach ($rows as $row) {
            $start = $bucket->startOf($row->time);
            $buckets[$start->getTimestamp()]['start'] = $start;
            $buckets[$start->getTimestamp()]['rows'][] = $row;
        }
        ksort($buckets);

        return array_values(array_map(static fn (array $part): MetricPoint => self::point($part['start'], $part['rows'], $statistic), $buckets));
    }

    /** @param list<MetricRow> $rows */
    private static function point(?\DateTimeImmutable $start, array $rows, MetricStatistic $statistic): MetricPoint
    {
        $values = array_values(array_filter(
            array_map(static fn (MetricRow $row): int|float|null => $row->value, $rows),
            static fn (int|float|null $value): bool => null !== $value,
        ));

        return new MetricPoint($start, $statistic->of($values), \count($values));
    }
}
