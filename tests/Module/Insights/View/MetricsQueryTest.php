<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\View;

use App\Module\Bridge\Metric\Formula\FormulaFormat;
use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricCompare;
use App\Module\Bridge\Metric\MetricGroup;
use App\Module\Bridge\Metric\MetricKey;
use App\Module\Bridge\Metric\MetricRange;
use App\Module\Bridge\Metric\MetricShape;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricUnit;
use App\Module\Bridge\Metric\MetricValueType;
use App\Module\Insights\View\MetricChartType;
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
        self::assertSame(MetricCompare::Off, $query->compare);
        self::assertSame(MetricChartType::Line, $query->chart);
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

    public function test_compare_and_chart_round_trip_and_default_ones_stay_out_of_the_route_params(): void
    {
        $params = ['compare' => 'previous', 'chart' => 'stacked-bars'];
        $query = self::from($params);

        self::assertSame(MetricCompare::Previous, $query->compare);
        self::assertSame(MetricChartType::StackedBars, $query->chart);
        self::assertSame($params, $query->routeParams());
        self::assertSame([], self::from(['compare' => 'off', 'chart' => 'line'])->routeParams());
        self::assertSame(['chart' => 'donut'], self::from(['chart' => 'donut'])->routeParams());
    }

    public function test_an_unknown_compare_or_chart_takes_the_default(): void
    {
        $query = self::from(['compare' => 'year', 'chart' => 'radar']);

        self::assertSame(MetricCompare::Off, $query->compare);
        self::assertSame(MetricChartType::Line, $query->chart);
        self::assertSame([], $query->routeParams());
    }

    public function test_all_time_cannot_compare(): void
    {
        $query = self::from(['range' => 'all', 'compare' => 'previous']);

        self::assertSame(MetricCompare::Off, $query->compare);
        self::assertSame(['range' => 'all'], $query->routeParams());
        self::assertSame(MetricCompare::Previous, self::from(['range' => 'seven-days', 'compare' => 'previous'])->compare);
    }

    public function test_the_grouped_bucket_metrics_are_a_choice_of_the_page_with_no_group(): void
    {
        $query = self::from(['metric' => 'bucket-time-each', 'group' => 'stage']);

        self::assertSame(Metric::BucketTimeEach, $query->metric->key->metric);
        self::assertSame(MetricGroup::None, $query->group);
        self::assertSame(['metric' => 'bucket-time-each'], $query->routeParams());
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

    private const string CACHE_ROWS = 'A:cache-read-tokens:sum,B:cache-write-tokens:sum';

    public function test_a_formula_round_trips_through_the_route_params_and_keeps_the_earliest_row_in_metric_and_statistic(): void
    {
        $params = ['rows' => self::CACHE_ROWS, 'formula' => 'A / B', 'format' => 'number'];
        $query = self::from($params + ['unit' => 'run']);

        self::assertNotNull($query->formula);
        self::assertNull($query->formula->problem);
        self::assertSame('cache-read-tokens', $query->metric->key->key());
        self::assertSame(MetricStatistic::Sum, $query->statistic);
        self::assertSame(['unit' => 'run'] + $params, $query->routeParams());
        self::assertSame(MetricValueType::Number, $query->chartMetric());
        self::assertNull($query->chartStatistic());
    }

    public function test_the_default_format_stays_out_of_the_route_params(): void
    {
        $query = self::from(['rows' => self::CACHE_ROWS, 'formula' => 'A / B']);

        self::assertSame(['rows' => self::CACHE_ROWS, 'formula' => 'A / B'], $query->routeParams());
        self::assertSame(FormulaFormat::Auto, $query->formula?->format);
    }

    public function test_a_one_metric_query_has_no_formula_and_draws_its_metric_and_statistic(): void
    {
        $query = self::from(['metric' => 'duration', 'statistic' => 'p90']);

        self::assertNull($query->formula);
        self::assertSame($query->metric, $query->chartMetric());
        self::assertSame(MetricStatistic::P90, $query->chartStatistic());
    }

    public function test_a_formula_ignores_the_metric_and_statistic_of_the_url(): void
    {
        $query = self::from(['rows' => self::CACHE_ROWS, 'formula' => 'A / B', 'metric' => 'duration', 'statistic' => 'p90']);

        self::assertSame('cache-read-tokens', $query->metric->key->key());
        self::assertSame(MetricStatistic::Sum, $query->statistic);
        self::assertArrayNotHasKey('metric', $query->routeParams());
        self::assertArrayNotHasKey('statistic', $query->routeParams());
    }

    public function test_a_unit_or_group_that_a_row_does_not_allow_falls_back(): void
    {
        $wakes = self::from(['rows' => 'A:wakes:sum,B:cost:sum', 'formula' => 'A / B', 'group' => 'wake-cache']);
        self::assertSame(MetricGroup::None, $wakes->group);

        $query = self::from(['rows' => self::CACHE_ROWS, 'formula' => 'A / B', 'unit' => 'reading', 'group' => 'model']);
        self::assertSame($query->formula?->units()[0], $query->unit);
        self::assertNotSame(MetricUnit::Reading, $query->unit);
        self::assertSame(MetricGroup::Model, $query->group);
    }

    public function test_a_formula_with_a_problem_is_kept_with_its_problem_and_draws_the_metric_of_its_first_row(): void
    {
        $query = self::from(['rows' => self::CACHE_ROWS, 'formula' => 'A / (B']);

        self::assertNotNull($query->formula?->problem);
        self::assertSame('cache-read-tokens', $query->metric->key->key());
        self::assertSame($query->metric, $query->chartMetric());

        $noRows = self::from(['formula' => 'A / B']);
        self::assertNotNull($noRows->formula?->problem);
        self::assertSame('cost', $noRows->metric->key->key());
        self::assertSame(MetricUnit::Card, $noRows->unit);
    }

    public function test_an_unknown_format_is_a_problem(): void
    {
        self::assertNotNull(self::from(['rows' => self::CACHE_ROWS, 'formula' => 'A / B', 'format' => 'pie'])->formula?->problem);
    }

    public function test_a_payload_serialized_before_the_formula_existed_unserializes_with_no_formula(): void
    {
        $query = self::from(['metric' => 'duration', 'unit' => 'run', 'statistic' => 'p90', 'group' => 'model', 'compare' => 'previous', 'chart' => 'bars']);
        // The old class had no formula property, so its payload holds one property less.
        $old = preg_replace('/s:7:"formula";N;/', '', serialize($query), -1, $removed);
        self::assertSame(1, $removed);
        $old = preg_replace('/^(O:\d+:"[^"]+":)9:/', '${1}8:', (string) $old, -1, $counted);
        self::assertSame(1, $counted);

        $restored = unserialize((string) $old);

        self::assertInstanceOf(MetricsQuery::class, $restored);
        self::assertNull($restored->formula);
        self::assertEquals($query, $restored);
    }

    public function test_a_query_with_a_formula_survives_a_round_trip_through_serialize(): void
    {
        $query = self::from(['rows' => self::CACHE_ROWS, 'formula' => 'A / B', 'format' => 'percent']);

        $restored = unserialize(serialize($query));

        self::assertEquals($query, $restored);
        self::assertInstanceOf(MetricsQuery::class, $restored);
        self::assertSame($query->routeParams(), $restored->routeParams());
    }

    /** @param array<string, string> $values */
    private static function from(array $values, TemplateMetricSet $templates = new TemplateMetricSet()): MetricsQuery
    {
        return MetricsQuery::fromQuery(new Request($values)->query, $templates);
    }
}
