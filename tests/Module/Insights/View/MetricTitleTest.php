<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\View;

use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricCompare;
use App\Module\Bridge\Metric\MetricGroup;
use App\Module\Bridge\Metric\MetricKey;
use App\Module\Bridge\Metric\MetricRange;
use App\Module\Bridge\Metric\MetricShape;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricUnit;
use App\Module\Insights\View\MetricTitle;
use App\Tests\Support\TemplateMetricSets;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\XliffFileLoader;
use Symfony\Component\Translation\Translator;

final class MetricTitleTest extends TestCase
{
    public function test_a_plain_graph_names_its_metric_unit_statistic_range_and_bucket(): void
    {
        self::assertSame(
            'Cost per finished card, median, last 90 days, per week',
            self::title(self::cost(), MetricUnit::Card, MetricStatistic::Median, MetricGroup::None, MetricRange::NinetyDays, MetricBucket::Week),
        );
    }

    public function test_a_group_follows_the_statistic(): void
    {
        self::assertSame(
            'Cost per finished card, median, by stage, last 90 days, per week',
            self::title(self::cost(), MetricUnit::Card, MetricStatistic::Median, MetricGroup::Stage, MetricRange::NinetyDays, MetricBucket::Week),
        );
    }

    public function test_a_compare_names_both_windows(): void
    {
        self::assertSame(
            'Cost per finished card, median, by stage, last 30 days vs the 30 days before, per week',
            self::title(self::cost(), MetricUnit::Card, MetricStatistic::Median, MetricGroup::Stage, MetricRange::ThirtyDays, MetricBucket::Week, MetricCompare::Previous),
        );
        self::assertSame(
            'Cost per run, 90th percentile, last 7 days vs the 7 days before, per day',
            self::title(self::cost(), MetricUnit::Run, MetricStatistic::P90, MetricGroup::None, MetricRange::SevenDays, MetricBucket::Day, MetricCompare::Previous),
        );
    }

    public function test_all_time_has_no_previous_window(): void
    {
        self::assertSame(
            'Runs per finished card, sum, all time, per month',
            self::title(new MetricShape(new MetricKey(Metric::Runs)), MetricUnit::Card, MetricStatistic::Sum, MetricGroup::None, MetricRange::All, MetricBucket::Month, MetricCompare::Previous),
        );
    }

    public function test_every_statistic_group_and_bucket_has_words(): void
    {
        $title = self::translator();
        foreach (MetricStatistic::cases() as $statistic) {
            foreach (MetricGroup::cases() as $group) {
                foreach (MetricBucket::cases() as $bucket) {
                    $text = $title->title(self::cost(), MetricUnit::Run, $statistic, $group, MetricRange::NinetyDays, $bucket);
                    self::assertStringNotContainsString('analytics.metrics.title', $text);
                }
            }
        }
    }

    public function test_it_names_every_kind_of_metric(): void
    {
        $title = self::translator();

        self::assertSame('Merge rate', $title->metricName(MetricShape::template(TemplateMetricSets::rate())));
        self::assertSame('Time in tests', $title->metricName(new MetricShape(new MetricKey(Metric::BucketTime, 'tests'))));
        self::assertSame('Time in tests, subagents included', $title->metricName(new MetricShape(new MetricKey(Metric::BucketTimeAll, 'tests'))));
        self::assertSame('Calls in lsp', $title->metricName(new MetricShape(new MetricKey(Metric::BucketCalls, 'lsp'))));
        self::assertSame('Harness events: diagnostics', $title->metricName(new MetricShape(new MetricKey(Metric::HarnessEvents, 'diagnostics'))));
        self::assertSame('Time in each bucket', $title->metricName(new MetricShape(new MetricKey(Metric::BucketTimeEach))));
        self::assertSame('Time in each bucket, subagents included', $title->metricName(new MetricShape(new MetricKey(Metric::BucketTimeAllEach))));
        self::assertSame('Calls in each bucket', $title->metricName(new MetricShape(new MetricKey(Metric::BucketCallsEach))));
        self::assertSame('Stop rate', $title->metricName(new MetricShape(new MetricKey(Metric::StopRate))));
    }

    private static function title(MetricShape $metric, MetricUnit $unit, MetricStatistic $statistic, MetricGroup $group, MetricRange $range, MetricBucket $bucket, MetricCompare $compare = MetricCompare::Off): string
    {
        return self::translator()->title($metric, $unit, $statistic, $group, $range, $bucket, $compare);
    }

    private static function translator(): MetricTitle
    {
        $translator = new Translator('en');
        $translator->addLoader('xlf', new XliffFileLoader());
        $translator->addResource('xlf', \dirname(__DIR__, 4).'/translations/messages.en.xlf', 'en');

        return new MetricTitle($translator);
    }

    private static function cost(): MetricShape
    {
        return new MetricShape(new MetricKey(Metric::Cost));
    }
}
