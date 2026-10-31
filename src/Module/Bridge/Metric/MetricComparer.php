<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric;

use App\Module\Bridge\Experiment\Confidence;
use App\Module\Bridge\Experiment\GoalState;
use App\Module\Bridge\Experiment\Interval;
use App\Module\Bridge\Experiment\Stats;

/**
 * Compares the rows of one line in two periods: the change, a likely range for each period and for the change, and
 * whether the change is real. Only the rows with a known value count, so the shown statistic, the ranges and the
 * minimum count of cards read the same values.
 */
final readonly class MetricComparer
{
    /**
     * @param list<MetricRow> $current
     * @param list<MetricRow> $previous
     * @param string          $seed     fixes the resamples; the period is added to it
     */
    public function compare(MetricStatistic $statistic, MetricValueType $type, MetricUnit $unit, array $current, array $previous, Confidence $confidence, string $seed): MetricComparison
    {
        $now = self::known($current);
        $before = self::known($previous);
        $nowValue = $statistic->of(self::values($now));
        $beforeValue = $statistic->of(self::values($before));
        $previousPoint = new MetricPoint(null, $beforeValue, \count($before));
        [$change, $kind] = [] === $now || [] === $before ? [null, null] : self::change($statistic, $type, $nowValue, $beforeValue);
        $cards = [self::distinctCards($now), self::distinctCards($before)];

        if (!self::hasSign($statistic)) {
            return new MetricComparison($previousPoint, $change, $kind, null, null, null, null, $cards[0], $cards[1]);
        }

        $currentRange = $this->range($statistic, $type, $unit, $now, $confidence, $seed.'|current');
        $previousRange = $this->range($statistic, $type, $unit, $before, $confidence, $seed.'|previous');
        $changeRange = null === $currentRange || null === $previousRange || null === $change || null === $kind || null === $beforeValue
            ? null
            : self::changeRange($currentRange, $previousRange, $kind, $beforeValue);

        return new MetricComparison(
            $previousPoint,
            $change,
            $kind,
            $currentRange,
            $previousRange,
            $changeRange,
            match (Stats::goalState($currentRange, $previousRange, $cards[0], $cards[1])) {
                GoalState::Clear => MetricSign::Clear,
                GoalState::NotClear => MetricSign::NotClearYet,
                default => MetricSign::NotEnoughCards,
            },
            $cards[0],
            $cards[1],
        );
    }

    /** A sum and a count add up what happened, so they have a change and no sign. */
    public static function hasSign(MetricStatistic $statistic): bool
    {
        return !\in_array($statistic, [MetricStatistic::Sum, MetricStatistic::Count], true);
    }

    /**
     * @param list<MetricRow> $rows the rows with a known value
     */
    private function range(MetricStatistic $statistic, MetricValueType $type, MetricUnit $unit, array $rows, Confidence $confidence, string $seed): ?Interval
    {
        if ([] === $rows) {
            return null;
        }
        $isRate = MetricValueType::Ratio === $type;
        // A graph per finished card has one independent value per card, and a rate has an exact range for that.
        if ($isRate && MetricUnit::Card === $unit) {
            return Stats::wilson(\count(array_filter($rows, static fn (MetricRow $row): bool => 0 !== $row->value && 0.0 !== $row->value)), \count($rows), $confidence);
        }

        /** @var array<string, list<int|float>> $clusters */
        $clusters = [];
        foreach ($rows as $index => $row) {
            // A run with no card is a cluster of its own, and so is each row of a graph per card.
            $key = MetricUnit::Run === $unit && null !== $row->cardNumber ? 'card:'.$row->cardNumber : 'row:'.$index;
            $clusters[$key][] = $row->value ?? throw new \LogicException('A known row has a value.');
        }

        return Stats::clusterBootstrap(array_values($clusters), $isRate ? null : $statistic, $seed, $confidence);
    }

    /**
     * @param list<MetricRow> $rows
     *
     * @return list<MetricRow>
     */
    private static function known(array $rows): array
    {
        return array_values(array_filter($rows, static fn (MetricRow $row): bool => null !== $row->value));
    }

    /**
     * @param list<MetricRow> $rows the rows with a known value
     *
     * @return list<int|float>
     */
    private static function values(array $rows): array
    {
        $values = [];
        foreach ($rows as $row) {
            $values[] = $row->value ?? throw new \LogicException('A known row has a value.');
        }

        return $values;
    }

    /** @param list<MetricRow> $rows */
    private static function distinctCards(array $rows): int
    {
        return \count(array_unique(array_filter(array_map(static fn (MetricRow $row): ?int => $row->cardNumber, $rows), static fn (?int $number): bool => null !== $number)));
    }

    /** @return array{?float, ?MetricChangeKind} */
    private static function change(MetricStatistic $statistic, MetricValueType $type, int|float|null $now, int|float|null $before): array
    {
        if (null === $now || null === $before) {
            return [null, null];
        }
        // A count of a rate counts rows, so only the rate itself changes in points.
        if (MetricValueType::Ratio === $type && MetricStatistic::Count !== $statistic) {
            return [(float) ($now - $before), MetricChangeKind::Points];
        }
        if (0.0 === (float) $before) {
            return [(float) ($now - $before), MetricChangeKind::Absolute];
        }

        return [($now - $before) / $before, MetricChangeKind::Relative];
    }

    /** The range holds zero exactly when the two ranges overlap, so the range and the sign cannot disagree. */
    private static function changeRange(Interval $now, Interval $before, MetricChangeKind $kind, int|float $beforeValue): Interval
    {
        $low = $now->low - $before->high;
        $high = $now->high - $before->low;
        $point = $now->point - $before->point;
        if (MetricChangeKind::Relative === $kind) {
            return new Interval($low / $beforeValue, $high / $beforeValue, $point / $beforeValue);
        }

        return new Interval($low, $high, $point);
    }
}
