<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\View;

use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricKey;
use App\Module\Bridge\Metric\MetricShape;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Insights\View\MetricChartLock;
use App\Module\Insights\View\MetricChartRules;
use App\Module\Insights\View\MetricChartType;
use App\Tests\Support\TemplateMetricSets;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MetricChartRulesTest extends TestCase
{
    private const array ADDING = [MetricChartType::StackedBars, MetricChartType::PercentStacked, MetricChartType::Donut];

    public function test_line_is_the_default_type(): void
    {
        self::assertSame(MetricChartType::Line, MetricChartType::default());
        self::assertSame(
            ['line', 'bars', 'stacked-bars', 'percent-stacked', 'donut', 'number'],
            array_map(static fn (MetricChartType $type): string => $type->value, MetricChartType::cases()),
        );
    }

    #[DataProvider('freeTypes')]
    public function test_line_bars_and_number_are_always_allowed(MetricChartType $type): void
    {
        foreach (MetricStatistic::cases() as $statistic) {
            foreach ([0, 1, 5] as $lines) {
                self::assertTrue(MetricChartRules::isAllowed($type, self::cost(), $statistic, $lines));
                self::assertNull(MetricChartRules::lock($type, self::cost(), $statistic, $lines));
            }
        }
    }

    /** @return iterable<string, array{MetricChartType}> */
    public static function freeTypes(): iterable
    {
        yield 'line' => [MetricChartType::Line];
        yield 'bars' => [MetricChartType::Bars];
        yield 'number' => [MetricChartType::Number];
    }

    /** @return iterable<string, array{MetricChartType}> */
    public static function addingTypes(): iterable
    {
        foreach (self::ADDING as $type) {
            yield $type->value => [$type];
        }
    }

    #[DataProvider('addingTypes')]
    public function test_a_sum_or_a_count_of_several_lines_unlocks_the_adding_types(MetricChartType $type): void
    {
        self::assertTrue(MetricChartRules::isAllowed($type, self::cost(), MetricStatistic::Sum, 2));
        self::assertTrue(MetricChartRules::isAllowed($type, self::cost(), MetricStatistic::Count, 2));
        self::assertTrue(MetricChartRules::isAllowed($type, new MetricShape(new MetricKey(Metric::Runs)), MetricStatistic::Sum, 11));
    }

    #[DataProvider('addingTypes')]
    public function test_a_median_a_percentile_and_a_mean_do_not_add_up(MetricChartType $type): void
    {
        foreach ([MetricStatistic::Median, MetricStatistic::P90, MetricStatistic::Mean] as $statistic) {
            self::assertFalse(MetricChartRules::isAllowed($type, self::cost(), $statistic, 3));
            self::assertSame(MetricChartLock::StatisticDoesNotAdd, MetricChartRules::lock($type, self::cost(), $statistic, 3));
        }
    }

    #[DataProvider('addingTypes')]
    public function test_one_line_does_not_unlock_the_adding_types(MetricChartType $type): void
    {
        foreach ([0, 1] as $lines) {
            self::assertFalse(MetricChartRules::isAllowed($type, self::cost(), MetricStatistic::Sum, $lines));
            self::assertSame(MetricChartLock::NeedsSeveralLines, MetricChartRules::lock($type, self::cost(), MetricStatistic::Sum, $lines));
        }
    }

    #[DataProvider('addingTypes')]
    public function test_a_mean_of_a_grouped_bucket_metric_adds_up(MetricChartType $type): void
    {
        foreach ([Metric::BucketTimeEach, Metric::BucketTimeAllEach, Metric::BucketCallsEach] as $metric) {
            $shape = new MetricShape(new MetricKey($metric));
            self::assertTrue(MetricChartRules::isAllowed($type, $shape, MetricStatistic::Mean, 4));
            self::assertTrue(MetricChartRules::isAllowed($type, $shape, MetricStatistic::Sum, 4));
            self::assertFalse(MetricChartRules::isAllowed($type, $shape, MetricStatistic::Median, 4));
            self::assertFalse(MetricChartRules::isAllowed($type, $shape, MetricStatistic::Mean, 1));
        }
    }

    #[DataProvider('addingTypes')]
    public function test_a_rate_never_unlocks_the_adding_types(MetricChartType $type): void
    {
        foreach ([new MetricShape(new MetricKey(Metric::StopRate)), MetricShape::template(TemplateMetricSets::rate())] as $rate) {
            foreach (MetricStatistic::cases() as $statistic) {
                self::assertFalse(MetricChartRules::isAllowed($type, $rate, $statistic, 6));
                self::assertSame(MetricChartLock::RateDoesNotAdd, MetricChartRules::lock($type, $rate, $statistic, 6));
            }
        }
    }

    public function test_the_reason_names_the_first_thing_to_change(): void
    {
        $rate = new MetricShape(new MetricKey(Metric::StopRate));

        self::assertSame(MetricChartLock::RateDoesNotAdd, MetricChartRules::lock(MetricChartType::Donut, $rate, MetricStatistic::Median, 1));
        self::assertSame(MetricChartLock::StatisticDoesNotAdd, MetricChartRules::lock(MetricChartType::Donut, self::cost(), MetricStatistic::Median, 1));
        self::assertSame(MetricChartLock::NeedsSeveralLines, MetricChartRules::lock(MetricChartType::Donut, self::cost(), MetricStatistic::Sum, 1));
    }

    public function test_a_locked_type_resolves_to_bars_with_its_reason(): void
    {
        $resolution = MetricChartRules::resolve(MetricChartType::StackedBars, self::cost(), MetricStatistic::Median, 5);

        self::assertSame(MetricChartType::Bars, $resolution->type);
        self::assertSame(MetricChartType::StackedBars, $resolution->requested);
        self::assertSame(MetricChartLock::StatisticDoesNotAdd, $resolution->reason);
        self::assertTrue($resolution->fellBack());
        self::assertSame(MetricChartType::Bars, MetricChartRules::fallback());
    }

    public function test_an_allowed_type_resolves_to_itself(): void
    {
        $resolution = MetricChartRules::resolve(MetricChartType::Donut, self::cost(), MetricStatistic::Sum, 5);

        self::assertSame(MetricChartType::Donut, $resolution->type);
        self::assertNull($resolution->reason);
        self::assertFalse($resolution->fellBack());
    }

    public function test_values_add_up_whatever_the_chart_type(): void
    {
        self::assertTrue(MetricChartRules::valuesAdd(self::cost(), MetricStatistic::Sum, 3));
        self::assertTrue(MetricChartRules::valuesAdd(self::cost(), MetricStatistic::Count, 2));
        self::assertTrue(MetricChartRules::valuesAdd(new MetricShape(new MetricKey(Metric::BucketTimeEach)), MetricStatistic::Mean, 3));
        self::assertFalse(MetricChartRules::valuesAdd(self::cost(), MetricStatistic::Sum, 1));
        self::assertFalse(MetricChartRules::valuesAdd(self::cost(), MetricStatistic::Median, 3));
        self::assertFalse(MetricChartRules::valuesAdd(new MetricShape(new MetricKey(Metric::StopRate)), MetricStatistic::Count, 3));
    }

    public function test_the_fallback_is_always_allowed(): void
    {
        self::assertTrue(MetricChartRules::isAllowed(MetricChartRules::fallback(), self::cost(), MetricStatistic::Median, 0));
    }

    private static function cost(): MetricShape
    {
        return new MetricShape(new MetricKey(Metric::Cost));
    }
}
