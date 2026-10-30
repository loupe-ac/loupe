<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricSeries;

/** The buckets of the time axis, each of one width, with the labels that mark them. */
final readonly class MetricChartTimeAxis
{
    private const int MAX_TIME_TICKS = 8;

    /**
     * @param list<\DateTimeImmutable> $starts   oldest first
     * @param list<MetricChartTick>    $ticks
     * @param array<int, int>          $position the place of each start, by timestamp
     */
    private function __construct(
        public MetricBucket $bucket,
        public array $starts,
        public float $bucketWidth,
        public array $ticks,
        /** True when the axis spans more than a year, so a label needs its year. */
        public bool $longSpan,
        private array $position,
    ) {
    }

    /**
     * Null when no series has a bucket. The previous points sit on the dates of the current ones, so they join the axis.
     *
     * @param list<MetricSeries> $series
     */
    public static function forSeries(MetricBucket $bucket, array $series, bool $withPrevious = false): ?self
    {
        $starts = [];
        foreach ($series as $item) {
            foreach ($withPrevious ? [...$item->points, ...$item->previousPoints] : $item->points as $point) {
                if (null !== $point->start) {
                    $starts[$point->start->getTimestamp()] = $point->start;
                }
            }
        }
        if ([] === $starts) {
            return null;
        }
        ksort($starts);
        $starts = array_values($starts);

        $bucketWidth = (MetricChartScale::PLOT_RIGHT - MetricChartScale::PLOT_LEFT) / \count($starts);
        $tickStep = self::tickStep($bucket, \count($starts));
        $ticks = [];
        foreach ($starts as $index => $start) {
            if (0 === $index % $tickStep) {
                $ticks[] = new MetricChartTick(self::middle($index, $bucketWidth), start: $start);
            }
        }

        return new self(
            $bucket,
            $starts,
            $bucketWidth,
            $ticks,
            (int) $starts[0]->diff($starts[\count($starts) - 1])->format('%a') > 366,
            array_flip(array_map(static fn (\DateTimeImmutable $start): int => $start->getTimestamp(), $starts)),
        );
    }

    public function count(): int
    {
        return \count($this->starts);
    }

    /** The place of a bucket on the axis. A start that the axis does not hold is a mistake of the caller. */
    public function index(\DateTimeImmutable $start): int
    {
        return $this->position[$start->getTimestamp()] ?? throw new \LogicException('The time axis holds no such bucket.');
    }

    public function left(int $index): float
    {
        return MetricChartScale::PLOT_LEFT + $index * $this->bucketWidth;
    }

    public function centre(int $index): float
    {
        return self::middle($index, $this->bucketWidth);
    }

    /** The last day that the bucket at $index covers. */
    public function lastDay(int $index): \DateTimeImmutable
    {
        $start = $this->starts[$index];

        return match ($this->bucket) {
            MetricBucket::Day => $start,
            MetricBucket::Week => $start->modify('+6 days'),
            MetricBucket::Month => $start->modify('last day of this month'),
        };
    }

    private static function middle(int $index, float $bucketWidth): float
    {
        return round(MetricChartScale::PLOT_LEFT + ($index + 0.5) * $bucketWidth, 2);
    }

    /** How many buckets one label of the time axis covers. */
    private static function tickStep(MetricBucket $bucket, int $bucketCount): int
    {
        $steps = match ($bucket) {
            MetricBucket::Day => [1, 2, 7, 14, 30, 61, 91, 182, 365],
            MetricBucket::Week => [1, 2, 4, 8, 13, 26, 52],
            MetricBucket::Month => [1, 2, 3, 6, 12, 24],
        };
        foreach ($steps as $step) {
            if (ceil($bucketCount / $step) <= self::MAX_TIME_TICKS) {
                return $step;
            }
        }

        return (int) ceil($bucketCount / self::MAX_TIME_TICKS);
    }
}
