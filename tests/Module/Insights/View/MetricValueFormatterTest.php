<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\View;

use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricKey;
use App\Module\Bridge\Metric\MetricShape;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricValueType;
use App\Module\Insights\View\MetricValueFormatter;
use App\Tests\Support\TemplateMetricSets;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MetricValueFormatterTest extends TestCase
{
    /** @return iterable<string, array{MetricShape, int|float, string}> */
    public static function values(): iterable
    {
        yield 'money' => [self::built(Metric::Cost), 1.5, '$1.50'];
        yield 'money rounds to cents' => [self::built(Metric::Cost), 0.123456, '$0.12'];
        yield 'money under half a cent keeps its micro-dollars' => [self::built(Metric::Cost), 0.0012345, '$0.001234'];
        yield 'one micro-dollar' => [self::built(Metric::Cost), 0.000001, '$0.000001'];
        yield 'zero money' => [self::built(Metric::Cost), 0, '$0.00'];
        yield 'tokens' => [self::built(Metric::InputTokens), 1234567, '1,234,567'];
        yield 'a mean of tokens keeps one decimal' => [self::built(Metric::OutputTokens), 1234.56, '1,234.6'];
        yield 'count' => [self::built(Metric::Runs), 3, '3'];
        yield 'milliseconds' => [self::built(Metric::Duration), 450, '450 ms'];
        yield 'seconds' => [self::built(Metric::Duration), 12_400, '12 s'];
        yield 'minutes and seconds' => [self::built(Metric::Duration), 192_000, '3 min 12 s'];
        yield 'hours and minutes' => [self::built(Metric::Duration), 7_500_000, '2 h 5 min'];
        yield 'a fraction of a millisecond rounds' => [self::built(Metric::Duration), 1.6, '2 ms'];
        yield 'a template duration is in hours' => [self::template(TemplateMetricSets::duration()), 5.24, '5.2 h'];
        yield 'a template rate' => [self::template(TemplateMetricSets::rate()), 0.125, '12.5%'];
        yield 'a whole ratio keeps its decimal' => [self::built(Metric::StopRate), 1, '100.0%'];
        yield 'a whole usage percent' => [self::built(Metric::AccountUsage), 96.0, '96 %'];
        yield 'a usage percent keeps one decimal' => [self::built(Metric::SessionUsage), 8.54, '8.5 %'];
        yield 'a usage past the limit' => [self::built(Metric::WeekUsage), 104, '104 %'];
        yield 'zero ratio' => [self::built(Metric::StopRate), 0, '0.0%'];
    }

    #[DataProvider('values')]
    public function test_it_formats_a_value_by_the_type_of_its_metric(MetricShape $metric, int|float $value, string $expected): void
    {
        self::assertSame($expected, new MetricValueFormatter('en')->format($value, $metric));
    }

    /** @return iterable<string, array{MetricShape}> */
    public static function metricsOfOtherTypes(): iterable
    {
        yield 'money' => [self::built(Metric::Cost)];
        yield 'duration' => [self::built(Metric::Duration)];
        yield 'a template duration' => [self::template(TemplateMetricSets::duration())];
        yield 'stop rate' => [self::built(Metric::StopRate)];
        yield 'a template rate' => [self::template(TemplateMetricSets::rate())];
    }

    #[DataProvider('metricsOfOtherTypes')]
    public function test_a_count_reads_as_a_count_whatever_the_metric(MetricShape $metric): void
    {
        self::assertSame('2', new MetricValueFormatter('en')->format(2, $metric, MetricStatistic::Count));
        self::assertSame('1,234', new MetricValueFormatter('en')->format(1234, $metric, MetricStatistic::Count));
    }

    public function test_an_unknown_value_has_no_text(): void
    {
        self::assertNull(new MetricValueFormatter('en')->format(null, self::built(Metric::Cost)));
    }

    public function test_money_takes_more_decimals_for_a_small_axis_step(): void
    {
        self::assertSame('$0.005', new MetricValueFormatter('en')->format(0.005, self::built(Metric::Cost), moneyDecimals: 3));
        self::assertSame('$5', new MetricValueFormatter('en')->format(5, self::built(Metric::Cost), moneyDecimals: 0));
    }

    public function test_a_bare_value_type_stands_for_a_built_in_metric(): void
    {
        self::assertSame('12 s', new MetricValueFormatter('en')->format(12_400, MetricValueType::Duration));
    }

    private static function built(Metric $metric): MetricShape
    {
        return new MetricShape(new MetricKey($metric));
    }

    private static function template(\App\Module\Workflow\Contract\TemplateMetric $metric): MetricShape
    {
        return MetricShape::template($metric);
    }
}
