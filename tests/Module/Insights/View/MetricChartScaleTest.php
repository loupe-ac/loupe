<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\View;

use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricKey;
use App\Module\Bridge\Metric\MetricShape;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Insights\View\MetricChartScale;
use App\Module\Insights\View\MetricValueFormatter;
use App\Tests\Support\TemplateMetricSets;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MetricChartScaleTest extends TestCase
{
    /** @return iterable<string, array{float, float, float}> */
    public static function durations(): iterable
    {
        yield 'about nine minutes' => [540_000.0, 600_000.0, 300_000.0];
        yield 'about 50 minutes' => [3_000_000.0, 3_600_000.0, 900_000.0];
        yield 'about three hours' => [10_000_000.0, 10_800_000.0, 3_600_000.0];
        yield 'under a second' => [700.0, 1_000.0, 500.0];
    }

    #[DataProvider('durations')]
    public function test_a_duration_axis_steps_in_whole_units(float $max, float $top, float $step): void
    {
        self::assertSame([$top, $step], MetricChartScale::axis(self::duration(), MetricStatistic::Mean, [$max]));
    }

    public function test_every_duration_tick_is_a_short_label(): void
    {
        $formatter = new MetricValueFormatter('en');
        foreach ([540_000.0, 3_000_000.0, 10_000_000.0, 40_000.0] as $max) {
            [$top, $step] = MetricChartScale::axis(self::duration(), MetricStatistic::Mean, [$max]);
            foreach (MetricChartScale::yTicks($top, $step) as $tick) {
                $label = (string) $formatter->format($tick->value, self::duration(), MetricStatistic::Mean);
                self::assertLessThanOrEqual(6, mb_strlen($label), \sprintf('Tick "%s" of a %s ms axis', $label, $max));
            }
        }
    }

    public function test_a_duration_in_hours_keeps_its_decimal_steps(): void
    {
        self::assertSame([6.0, 2.0], MetricChartScale::axis(MetricShape::template(TemplateMetricSets::duration()), MetricStatistic::Mean, [5.24]));
    }

    private static function duration(): MetricShape
    {
        return new MetricShape(new MetricKey(Metric::Duration));
    }
}
