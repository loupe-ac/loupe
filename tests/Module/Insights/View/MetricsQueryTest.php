<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\View;

use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricGroup;
use App\Module\Bridge\Metric\MetricKey;
use App\Module\Bridge\Metric\MetricRange;
use App\Module\Bridge\Metric\MetricShape;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricUnit;
use App\Module\Insights\View\MetricsQuery;
use App\Module\Workflow\Contract\TemplateMetricSet;
use App\Tests\Support\TemplateMetricSets;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class MetricsQueryTest extends TestCase
{
    public function test_an_empty_query_gives_the_defaults_and_no_route_params(): void
    {
        $query = self::from([]);

        self::assertSame(MetricUnit::Card, $query->unit);
        self::assertSame('cost', $query->metric->key->key());
        self::assertSame(MetricStatistic::Median, $query->statistic);
        self::assertSame(MetricGroup::None, $query->group);
        self::assertSame(MetricRange::NinetyDays, $query->range);
        self::assertSame(MetricBucket::Week, $query->bucket);
        self::assertSame([], $query->routeParams());
    }

    public function test_a_full_query_round_trips_through_the_route_params(): void
    {
        $params = ['unit' => 'run', 'metric' => 'duration', 'statistic' => 'p90', 'group' => 'model', 'range' => 'all', 'bucket' => 'day'];

        self::assertSame($params, self::from($params)->routeParams());
    }

    public function test_an_unknown_value_falls_back_to_the_default(): void
    {
        $query = self::from(['unit' => 'team', 'metric' => 'joy', 'statistic' => 'mode', 'group' => 'colour', 'range' => 'week', 'bucket' => 'year']);

        self::assertSame([], $query->routeParams());
    }

    public function test_a_value_the_metric_does_not_allow_falls_back_to_one_it_allows(): void
    {
        $query = self::from(['unit' => 'run', 'metric' => 'merge-rate', 'statistic' => 'median', 'group' => 'model'], TemplateMetricSets::all());

        self::assertSame(MetricUnit::Card, $query->unit);
        self::assertSame(MetricStatistic::Mean, $query->statistic);
        self::assertSame(MetricGroup::None, $query->group);
        self::assertTrue($query->metric->isTemplateCardMetric());

        $stopRate = self::from(['metric' => 'stop-rate']);
        self::assertSame(MetricUnit::Run, $stopRate->unit);
        self::assertSame(['unit' => 'run', 'metric' => 'stop-rate', 'statistic' => 'mean'], $stopRate->routeParams());
    }

    public function test_a_template_metric_round_trips_through_the_route_params(): void
    {
        $params = ['metric' => 'hours-to-merge', 'group' => 'variant'];
        $query = self::from($params, TemplateMetricSets::all());

        self::assertSame('hours-to-merge', $query->metric->key->templateKey);
        self::assertSame($params, $query->routeParams());
    }

    public function test_a_template_key_the_project_does_not_declare_falls_back_to_cost(): void
    {
        $query = self::from(['metric' => 'merge-rate', 'unit' => 'run']);

        self::assertSame('cost', $query->metric->key->key());
        self::assertSame(MetricUnit::Run, $query->unit);
        self::assertSame(['unit' => 'run'], $query->routeParams());
    }

    public function test_seven_days_fall_back_to_the_bucket_day(): void
    {
        $query = self::from(['range' => 'seven-days', 'bucket' => 'week']);

        self::assertSame(MetricRange::SevenDays, $query->range);
        self::assertSame(MetricBucket::Day, $query->bucket);
        self::assertSame(['range' => 'seven-days', 'bucket' => 'day'], $query->routeParams());
        self::assertSame(MetricBucket::Day, self::from(['range' => 'seven-days'])->bucket);
        self::assertSame(MetricBucket::Month, self::from(['range' => 'thirty-days', 'bucket' => 'month'])->bucket);
    }

    public function test_bucket_time_is_no_choice_of_the_page(): void
    {
        self::assertSame('cost', self::from(['metric' => 'bucket-time'])->metric->key->key());
        self::assertNotContains(Metric::BucketTime, Metric::standalone());
    }

    public function test_a_bucket_key_reads_the_time_of_that_bucket_and_round_trips(): void
    {
        $params = ['unit' => 'run', 'metric' => 'bucket-time:git', 'statistic' => 'p90', 'group' => 'model', 'range' => 'all', 'bucket' => 'day'];
        $query = self::from($params);

        self::assertSame(Metric::BucketTime, $query->metric->key->metric);
        self::assertSame('git', $query->metric->key->name);
        self::assertSame($params, $query->routeParams());
    }

    public function test_a_harness_events_key_reads_the_count_of_that_kind_and_round_trips(): void
    {
        $params = ['unit' => 'run', 'metric' => 'harness-events:hook_success', 'statistic' => 'sum', 'group' => 'model', 'range' => 'all', 'bucket' => 'day'];
        $query = self::from($params);

        self::assertSame(Metric::HarnessEvents, $query->metric->key->metric);
        self::assertSame('hook_success', $query->metric->key->name);
        self::assertSame($params, $query->routeParams());
        self::assertSame('cost', self::from(['metric' => 'harness-events:Bad'])->metric->key->key());
        self::assertSame('cost', self::from(['metric' => 'harness-events'])->metric->key->key());
    }

    public function test_a_standalone_metric_has_no_bucket_name(): void
    {
        self::assertNull(self::from(['metric' => 'duration'])->metric->key->name);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidBucketKeys(): iterable
    {
        yield 'no name' => ['bucket-time:'];
        yield 'upper case' => ['bucket-time:Git'];
        yield 'space' => ['bucket-time:a b'];
        yield 'too long' => ['bucket-time:'.str_repeat('a', 65)];
        yield 'name on a standalone metric' => ['cost:git'];
    }

    #[DataProvider('invalidBucketKeys')]
    public function test_an_invalid_bucket_key_falls_back_to_cost(string $metric): void
    {
        $query = self::from(['metric' => $metric]);

        self::assertSame('cost', $query->metric->key->key());
        self::assertNull($query->metric->key->name);
        self::assertSame([], $query->routeParams());
    }

    public function test_a_bucket_key_takes_the_controls_that_bucket_time_allows(): void
    {
        $shape = new MetricShape(new MetricKey(Metric::BucketTime, 'git'));
        foreach (MetricUnit::cases() as $unit) {
            foreach (MetricStatistic::cases() as $statistic) {
                foreach (MetricGroup::cases() as $group) {
                    $query = self::from(['metric' => 'bucket-time:git', 'unit' => $unit->value, 'statistic' => $statistic->value, 'group' => $group->value]);

                    self::assertContains($query->unit, $shape->units());
                    self::assertContains($query->statistic, $shape->statistics());
                    self::assertContains($query->group, $shape->groups());
                }
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function metrics(): iterable
    {
        foreach (Metric::standalone() as $metric) {
            yield $metric->value => [$metric->value];
        }
        foreach (TemplateMetricSets::all()->metrics as $metric) {
            yield 'template '.$metric->key => [$metric->key];
        }
    }

    #[DataProvider('metrics')]
    public function test_every_metric_lands_on_a_combination_the_handler_accepts(string $key): void
    {
        foreach (MetricUnit::cases() as $unit) {
            foreach (MetricStatistic::cases() as $statistic) {
                foreach (MetricGroup::cases() as $group) {
                    $query = self::from(['metric' => $key, 'unit' => $unit->value, 'statistic' => $statistic->value, 'group' => $group->value], TemplateMetricSets::all());

                    self::assertSame($key, $query->metric->key->key());
                    self::assertContains($query->unit, $query->metric->units());
                    self::assertContains($query->statistic, $query->metric->statistics());
                    self::assertContains($query->group, $query->metric->groups());
                }
            }
        }
    }

    /** @param array<string, string> $values */
    private static function from(array $values, TemplateMetricSet $templates = new TemplateMetricSet()): MetricsQuery
    {
        return MetricsQuery::fromQuery(new Request($values)->query, $templates);
    }
}
