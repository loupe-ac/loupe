<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric\Formula;

use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricChangeKind;
use App\Module\Bridge\Metric\MetricComparison;
use App\Module\Bridge\Metric\MetricGroup;
use App\Module\Bridge\Metric\MetricGroupKey;
use App\Module\Bridge\Metric\MetricGroupSplit;
use App\Module\Bridge\Metric\MetricPoint;
use App\Module\Bridge\Metric\MetricRow;
use App\Module\Bridge\Metric\MetricSeries;
use App\Module\Bridge\Metric\MetricStatistic;

/**
 * Turns the rows of every letter into the lines of a graph. Each letter reads its statistic for each bucket and group,
 * and the formula combines the results. The lines join on the group key and on the bucket start, and a line or a bucket
 * that one letter lacks reads as that letter's statistic over no rows. The `rows` of a series hold one row with no value for
 * each run or card, so a layout still sees the data.
 */
final readonly class FormulaSeriesBuilder
{
    private FormulaNode $tree;

    /** @var array<string, MetricStatistic> by letter */
    private array $statistics;

    /** @var list<string> */
    private array $used;

    public function __construct(
        private MetricFormula $formula,
    ) {
        $this->tree = $formula->tree ?? throw new \LogicException('A formula with a problem has no series.');
        $statistics = [];
        foreach ($formula->rows as $row) {
            $statistics[$row->letter] = $row->statistic;
        }
        $this->statistics = $statistics;
        // A formula with no letter still reads every configured row, so they give it its buckets and groups.
        $this->used = FormulaEvaluator::lettersOf($this->tree) ?: array_map(strval(...), array_keys($statistics));
    }

    /**
     * @param array<string, list<MetricRow>>  $rows         the rows of each letter
     * @param ?array<string, list<MetricRow>> $previousRows the rows of each letter in the previous period, already moved onto the dates of the current one, or null when the query does not compare
     *
     * @return list<MetricSeries>
     */
    public function build(MetricGroup $group, MetricBucket $bucket, array $rows, ?array $previousRows): array
    {
        $current = self::splitByLetter($this->usedOf($rows));
        $previous = self::splitByLetter($this->usedOf($previousRows ?? []));

        // A line that only one letter or only the previous period holds is still a line of the graph.
        $lines = [];
        foreach ([...array_values($current), ...array_values($previous)] as $perGroup) {
            foreach ($perGroup as $key => $part) {
                $lines[$key] ??= ['label' => $part['label'], 'group' => $part['group'], 'groupKey' => $part['groupKey']];
            }
        }

        $series = [];
        foreach (MetricGroupSplit::sort($lines, $group) as $key => $line) {
            $now = self::ofLine($current, $key);
            $before = null === $previousRows ? null : self::ofLine($previous, $key);

            $total = $this->point(null, $now);
            $series[] = new MetricSeries(
                $line['group'],
                $this->points($now, $bucket),
                $total,
                self::unitRows($now),
                null === $before ? null : $this->comparison($total, $this->point(null, $before)),
                null === $before ? [] : $this->points($before, $bucket),
                $line['groupKey'],
            );
        }

        return $series;
    }

    /**
     * @param array<string, array<string, array{label: string, group: ?string, groupKey: ?MetricGroupKey, rows: list<MetricRow>}>> $split by letter, then by line
     *
     * @return array<string, list<MetricRow>> the rows of one line, by letter
     */
    private static function ofLine(array $split, string $key): array
    {
        $rows = [];
        foreach ($split as $letter => $perLine) {
            $rows[$letter] = $perLine[$key]['rows'] ?? [];
        }

        return $rows;
    }

    /**
     * A row that the formula does not use adds no line and no unit.
     *
     * @param array<string, list<MetricRow>> $rows
     *
     * @return array<string, list<MetricRow>>
     */
    private function usedOf(array $rows): array
    {
        return array_intersect_key($rows, array_flip($this->used));
    }

    /**
     * @param array<string, list<MetricRow>> $rows
     *
     * @return array<string, array<string, array{label: string, group: ?string, groupKey: ?MetricGroupKey, rows: list<MetricRow>}>> by letter, then by line
     */
    private static function splitByLetter(array $rows): array
    {
        return array_map(MetricGroupSplit::split(...), $rows);
    }

    /**
     * @param array<string, list<MetricRow>> $rows the rows of one line, by letter
     *
     * @return list<MetricPoint>
     */
    private function points(array $rows, MetricBucket $bucket): array
    {
        /** @var array<int, \DateTimeImmutable> $starts */
        $starts = [];
        /** @var array<string, array<int, list<MetricRow>>> $perBucket */
        $perBucket = [];
        foreach ($rows as $letter => $letterRows) {
            foreach ($letterRows as $row) {
                $start = $bucket->startOf($row->time);
                $starts[$start->getTimestamp()] = $start;
                $perBucket[$letter][$start->getTimestamp()][] = $row;
            }
        }
        ksort($starts);

        $points = [];
        foreach ($starts as $timestamp => $start) {
            $points[] = $this->point($start, array_map(static fn (array $part): array => $part[$timestamp] ?? [], $perBucket));
        }

        return $points;
    }

    /**
     * The rows behind a point are the most rows with a known value that any letter of the formula reads.
     *
     * @param array<string, list<MetricRow>> $rows the rows of each letter in this point
     */
    private function point(?\DateTimeImmutable $start, array $rows): MetricPoint
    {
        $values = [];
        $count = 0;
        foreach ($this->used as $letter) {
            $known = [];
            foreach ($rows[$letter] ?? [] as $row) {
                if (null !== $row->value) {
                    $known[] = $row->value;
                }
            }
            $values[$letter] = ($this->statistics[$letter] ?? throw new \LogicException('A used letter has a row.'))->of($known);
            $count = max($count, \count($known));
        }

        return new MetricPoint($start, FormulaEvaluator::evaluate($this->tree, $values), $count);
    }

    /**
     * One row with no value for each run or card of the line, newest first.
     *
     * @param array<string, list<MetricRow>> $rows
     *
     * @return list<MetricRow>
     */
    private static function unitRows(array $rows): array
    {
        $units = [];
        foreach ($rows as $letterRows) {
            foreach ($letterRows as $row) {
                $units[(string) $row->unitId] ??= new MetricRow($row->unitId, $row->cardNumber, $row->group, $row->time, null, $row->groupKey);
            }
        }
        $units = array_values($units);
        usort($units, static fn (MetricRow $a, MetricRow $b): int => [$b->time, (string) $b->unitId] <=> [$a->time, (string) $a->unitId]);

        return $units;
    }

    /** A formula has no value per run to resample, so the comparison holds the change alone. */
    private function comparison(MetricPoint $now, MetricPoint $before): MetricComparison
    {
        $change = null;
        $kind = null;
        if (null !== $now->value && null !== $before->value) {
            if (FormulaFormat::Percent === $this->formula->format) {
                [$change, $kind] = [(float) ($now->value - $before->value), MetricChangeKind::Points];
            } elseif (0.0 === (float) $before->value) {
                [$change, $kind] = [(float) ($now->value - $before->value), MetricChangeKind::Absolute];
            } else {
                [$change, $kind] = [($now->value - $before->value) / $before->value, MetricChangeKind::Relative];
            }
        }

        return new MetricComparison($before, $change, $kind, null, null, null, null, 0, 0);
    }
}
