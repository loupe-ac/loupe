<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Metric;

use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricGroup;
use App\Module\Bridge\Metric\MetricKey;
use App\Module\Bridge\Metric\MetricShape;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricUnit;
use App\Module\Bridge\Metric\MetricValueType;
use App\Module\Workflow\Contract\TemplateMetricSet;
use App\Tests\Support\TemplateMetricSets;
use PHPUnit\Framework\TestCase;

final class MetricShapeTest extends TestCase
{
    public function test_a_built_in_key_takes_the_shape_of_its_metric(): void
    {
        foreach (Metric::standalone() as $metric) {
            $shape = MetricShape::of(new MetricKey($metric), new TemplateMetricSet());

            self::assertNotNull($shape);
            self::assertSame($metric->units(), $shape->units());
            self::assertSame($metric->valueType(), $shape->valueType());
            self::assertSame($metric->statistics(), $shape->statistics());
            self::assertSame($metric->groups(), $shape->groups());
            self::assertSame($metric->isAdditive(), $shape->isAdditive());
            self::assertSame($metric->description(), $shape->description());
            self::assertFalse($shape->isTemplateCardMetric());
            self::assertFalse($shape->isInHours());
        }
    }

    public function test_the_account_usage_reads_readings_grouped_by_account_window(): void
    {
        $shape = new MetricShape(new MetricKey(Metric::AccountUsage));

        self::assertSame([MetricUnit::Reading], $shape->units());
        self::assertSame([MetricGroup::AccountWindow], $shape->groups());
        self::assertSame(MetricValueType::Percent, $shape->valueType());
        self::assertSame([MetricStatistic::Max, MetricStatistic::Mean, MetricStatistic::Median, MetricStatistic::P90, MetricStatistic::Count], $shape->statistics());
        self::assertFalse($shape->isAdditive());
    }

    public function test_the_run_usage_metrics_are_run_percents_with_no_account_window_group(): void
    {
        foreach ([Metric::SessionUsage, Metric::WeekUsage] as $metric) {
            $shape = new MetricShape(new MetricKey($metric));

            self::assertSame([MetricUnit::Run], $shape->units(), $metric->value);
            self::assertSame(MetricValueType::Percent, $shape->valueType(), $metric->value);
            self::assertNotContains(MetricGroup::AccountWindow, $shape->groups(), $metric->value);
            self::assertContains(MetricGroup::None, $shape->groups(), $metric->value);
            self::assertFalse($shape->isAdditive(), $metric->value);
        }
    }

    public function test_only_a_percent_offers_the_max_and_only_a_ratio_or_a_percent_leaves_out_the_sum(): void
    {
        foreach (MetricValueType::cases() as $type) {
            self::assertSame(MetricValueType::Percent === $type, \in_array(MetricStatistic::Max, $type->statistics(), true), $type->value);
        }
        self::assertNotContains(MetricStatistic::Sum, MetricValueType::Percent->statistics());
    }

    public function test_no_other_metric_takes_the_account_window_group_or_the_reading_unit(): void
    {
        foreach (Metric::cases() as $metric) {
            if (Metric::AccountUsage === $metric) {
                continue;
            }
            self::assertNotContains(MetricGroup::AccountWindow, $metric->groups(), $metric->value);
            self::assertNotContains(MetricUnit::Reading, $metric->units(), $metric->value);
        }
    }

    public function test_a_template_rate_is_a_ratio_of_cards(): void
    {
        $shape = MetricShape::of(MetricKey::template('merge-rate'), TemplateMetricSets::all());

        self::assertNotNull($shape);
        self::assertTrue($shape->isTemplateCardMetric());
        self::assertSame([MetricUnit::Card], $shape->units());
        self::assertSame(MetricValueType::Ratio, $shape->valueType());
        self::assertSame([MetricStatistic::Mean, MetricStatistic::Count], $shape->statistics());
        self::assertSame([MetricGroup::Variant, MetricGroup::CardType, MetricGroup::None], $shape->groups());
        self::assertFalse($shape->isAdditive());
        self::assertFalse($shape->isInHours());
        self::assertSame(TemplateMetricSets::rate()->description, $shape->description());
    }

    public function test_a_template_count_has_the_count_type_and_every_statistic(): void
    {
        $shape = MetricShape::template(TemplateMetricSets::count());

        self::assertSame(MetricValueType::Count, $shape->valueType());
        self::assertSame([MetricStatistic::Median, MetricStatistic::Mean, MetricStatistic::Sum, MetricStatistic::P90, MetricStatistic::Count], $shape->statistics());
        self::assertSame([MetricUnit::Card], $shape->units());
    }

    public function test_a_template_duration_is_in_hours(): void
    {
        $shape = MetricShape::template(TemplateMetricSets::duration());

        self::assertSame(MetricValueType::Duration, $shape->valueType());
        self::assertTrue($shape->isInHours());
        self::assertFalse($shape->isAdditive());
    }

    public function test_a_built_in_duration_is_not_in_hours(): void
    {
        self::assertFalse(new MetricShape(new MetricKey(Metric::Duration))->isInHours());
    }

    public function test_a_bucket_key_takes_the_shape_of_bucket_time(): void
    {
        $shape = new MetricShape(new MetricKey(Metric::BucketTime, 'git'));

        self::assertSame(MetricValueType::Duration, $shape->valueType());
        self::assertSame(Metric::BucketTime->units(), $shape->units());
    }

    public function test_a_template_key_the_set_does_not_hold_has_no_shape(): void
    {
        self::assertNull(MetricShape::of(MetricKey::template('merge-rate'), new TemplateMetricSet()));
    }

    public function test_a_template_key_and_its_metric_go_together(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MetricShape(MetricKey::template('fix-rounds'), TemplateMetricSets::rate());
    }
}
