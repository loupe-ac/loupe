<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Exception\DomainErrors;
use App\Module\Bridge\Experiment\Confidence;
use App\Module\Bridge\Metric\Formula\FormulaRow;
use App\Module\Bridge\Metric\Formula\FormulaSeriesBuilder;
use App\Module\Bridge\Metric\Formula\MetricFormula;
use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricCompare;
use App\Module\Bridge\Metric\MetricComparer;
use App\Module\Bridge\Metric\MetricComparison;
use App\Module\Bridge\Metric\MetricGroupKey;
use App\Module\Bridge\Metric\MetricGroupSplit;
use App\Module\Bridge\Metric\MetricPoint;
use App\Module\Bridge\Metric\MetricRow;
use App\Module\Bridge\Metric\MetricRowSource;
use App\Module\Bridge\Metric\MetricSeries;
use App\Module\Bridge\Metric\MetricShape;
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

    /** The error of the formula field is the key of the problem itself, such as `bridge.metric.formula.problem.bad_row`. */
    public const string FORMULA_FIELD = 'formula';

    public function __construct(
        private MetricRowSource $rows,
        private ClockInterface $clock,
        private MetricComparer $comparer = new MetricComparer(),
    ) {
    }

    public function __invoke(MetricQueryCommand $command): MetricQueryView
    {
        $formula = $command->formula;
        $metric = $command->metric;
        $errors = [];
        if (null !== $formula) {
            $errors = self::formulaErrors($formula, $command);
        } else {
            if (!\in_array($command->unit, $metric->units(), true)) {
                $errors['unit'] = self::UNIT_NOT_ALLOWED;
            }
            if (!\in_array($command->statistic, $metric->statistics(), true)) {
                $errors['statistic'] = self::STATISTIC_NOT_ALLOWED;
            }
            if (!\in_array($command->group, $metric->groups(), true)) {
                $errors['group'] = self::GROUP_NOT_ALLOWED;
            }
        }
        if (!\in_array($command->bucket, $command->range->allowedBuckets(), true)) {
            $errors['bucket'] = self::BUCKET_NOT_ALLOWED;
        }
        if (MetricCompare::Previous === $command->compare && null === $command->range->length()) {
            $errors['compare'] = self::COMPARE_NOT_ALLOWED;
        }
        if ([] !== $errors) {
            throw new DomainErrors($errors, null === $formula?->problem ? [] : [self::FORMULA_FIELD => $formula->problem->parameters]);
        }

        // Both periods count back from one clock reading.
        $now = $command->now ?? $this->clock->now();
        $from = $command->range->startFrom($now);
        if (null !== $formula) {
            return $this->formulaView($command, $formula, $now, $from);
        }
        $rows = $this->rows->rows($command->project, $command->unit, $metric, $command->group, $from, $now);

        $previousWindow = null;
        $previousRows = [];
        if (MetricCompare::Previous === $command->compare) {
            $length = $command->range->length() ?? throw new \LogicException('A range with a length starts at a time.');
            $previousFrom = ($from ?? throw new \LogicException('A range with a length starts at a time.'))->sub($length);
            $previousWindow = new MetricWindow($previousFrom, $from);
            // The previous rows move forward by the length, so they fall into the same buckets as the current rows.
            $previousRows = array_map(
                static fn (MetricRow $row): MetricRow => $row->movedBy($length),
                $this->rows->rows($command->project, $command->unit, $metric, $command->group, $previousFrom, $from),
            );
            if (true === $metric->key->metric?->isBucketEach()) {
                $names = array_values(array_unique(array_map(static fn (MetricRow $row): string => (string) $row->group, [...$rows, ...$previousRows])));
                $rows = MetricRowSource::withBucketNames($rows, $names);
                $previousRows = MetricRowSource::withBucketNames($previousRows, $names);
            }
        }

        $byGroup = MetricGroupSplit::split($rows);
        $previousByGroup = MetricGroupSplit::split($previousRows);
        // A line that only the previous period holds is still a line of the graph.
        foreach ($previousByGroup as $key => $part) {
            $byGroup[$key] ??= ['label' => $part['label'], 'group' => $part['group'], 'groupKey' => $part['groupKey'], 'rows' => []];
        }
        $byGroup = MetricGroupSplit::sort($byGroup, $command->group);

        $compare = null !== $previousWindow;
        // Every line that has a sign widens every range, so a graph with many lines draws a sign by chance more rarely.
        $confidence = Confidence::forComparisons(MetricComparer::hasSign($command->statistic) ? \count($byGroup) : 1);
        $seed = \sprintf('%s|%s|%s', $command->project->requireId()->toRfc4122(), $metric->key->key(), $command->statistic->value);

        $series = [];
        foreach ($byGroup as $key => $group) {
            $previousOfGroup = $previousByGroup[$key]['rows'] ?? [];
            $series[] = self::series(
                $group['group'],
                $group['groupKey'],
                $group['rows'],
                $command->statistic,
                $command->bucket,
                $compare ? $previousOfGroup : null,
                $compare ? $this->comparer->compare($command->statistic, $metric->valueType(), $command->unit, $group['rows'], $previousOfGroup, $confidence, $seed.'|'.$group['label']) : null,
            );
        }

        return new MetricQueryView($command, $series, new MetricWindow($from, $now), $previousWindow);
    }

    /** @return array<string, string> */
    private static function formulaErrors(MetricFormula $formula, MetricQueryCommand $command): array
    {
        $errors = [];
        if (null !== $formula->problem) {
            $errors[self::FORMULA_FIELD] = $formula->problem->key;
        } else {
            if (!\in_array($command->unit, $formula->units(), true)) {
                $errors['unit'] = self::UNIT_NOT_ALLOWED;
            }
            if (!\in_array($command->group, $formula->groups(), true)) {
                $errors['group'] = self::GROUP_NOT_ALLOWED;
            }
        }

        return $errors;
    }

    private function formulaView(MetricQueryCommand $command, MetricFormula $formula, \DateTimeImmutable $now, ?\DateTimeImmutable $from): MetricQueryView
    {
        $shapes = array_map(static fn (FormulaRow $row): MetricShape => $row->shape, $formula->rows);
        $letters = array_map(static fn (FormulaRow $row): string => $row->letter, $formula->rows);
        $rows = array_combine($letters, $this->rows->rowsForMany($command->project, $command->unit, $shapes, $command->group, $from, $now));

        $previousWindow = null;
        $previousRows = null;
        if (MetricCompare::Previous === $command->compare) {
            $length = $command->range->length() ?? throw new \LogicException('A range with a length starts at a time.');
            $previousFrom = ($from ?? throw new \LogicException('A range with a length starts at a time.'))->sub($length);
            $previousWindow = new MetricWindow($previousFrom, $from);
            // The previous rows move forward by the length, so they fall into the same buckets as the current rows.
            $previousRows = array_map(
                static fn (array $letterRows): array => array_map(static fn (MetricRow $row): MetricRow => $row->movedBy($length), $letterRows),
                array_combine($letters, $this->rows->rowsForMany($command->project, $command->unit, $shapes, $command->group, $previousFrom, $from)),
            );
        }

        $series = new FormulaSeriesBuilder($formula)->build($command->group, $command->bucket, $rows, $previousRows);

        return new MetricQueryView($command, $series, new MetricWindow($from, $now), $previousWindow);
    }

    /**
     * @param list<MetricRow>  $rows
     * @param ?list<MetricRow> $previousRows the previous period already moved onto the current dates, null when the query does not compare
     */
    private static function series(?string $group, ?MetricGroupKey $groupKey, array $rows, MetricStatistic $statistic, MetricBucket $bucket, ?array $previousRows, ?MetricComparison $comparison): MetricSeries
    {
        usort($rows, static fn (MetricRow $a, MetricRow $b): int => [$b->time, (string) $b->unitId] <=> [$a->time, (string) $a->unitId]);

        return new MetricSeries(
            $group,
            self::points($rows, $statistic, $bucket),
            self::point(null, $rows, $statistic),
            $rows,
            $comparison,
            null === $previousRows ? [] : self::points($previousRows, $statistic, $bucket),
            $groupKey,
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
