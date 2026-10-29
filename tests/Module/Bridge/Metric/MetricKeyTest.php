<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Metric;

use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricKey;
use App\Module\Workflow\Contract\TemplateMetricSet;
use App\Tests\Support\TemplateMetricSets;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MetricKeyTest extends TestCase
{
    public function test_a_standalone_key_parses_with_no_name(): void
    {
        foreach (Metric::standalone() as $metric) {
            $key = MetricKey::tryParse($metric->value, new TemplateMetricSet());

            self::assertNotNull($key);
            self::assertSame($metric, $key->metric);
            self::assertNull($key->name);
            self::assertNull($key->templateKey);
            self::assertFalse($key->isTemplate());
            self::assertSame($metric->value, $key->key());
        }
    }

    public function test_a_bucket_key_parses_with_its_bucket_name(): void
    {
        $key = MetricKey::tryParse('bucket-time:git_push-2', new TemplateMetricSet());

        self::assertNotNull($key);
        self::assertSame(Metric::BucketTime, $key->metric);
        self::assertSame('git_push-2', $key->name);
        self::assertSame('bucket-time:git_push-2', $key->key());
    }

    public function test_the_bucket_call_and_time_with_subagents_keys_parse_with_a_bucket_name(): void
    {
        foreach ([Metric::BucketCalls, Metric::BucketTimeAll] as $metric) {
            $key = MetricKey::tryParse($metric->value.':git_push-2', new TemplateMetricSet());

            self::assertSame($metric, $key?->metric);
            self::assertSame('git_push-2', $key->name);
            self::assertSame($metric->value.':git_push-2', $key->key());
        }
        self::assertSame(Metric::BucketTime, MetricKey::tryParse('bucket-time:all', new TemplateMetricSet())?->metric);
        self::assertSame(\App\Module\Bridge\Metric\MetricValueType::Count, Metric::BucketCalls->valueType());
        self::assertSame(\App\Module\Bridge\Metric\MetricValueType::Duration, Metric::BucketTimeAll->valueType());
    }

    public function test_a_harness_events_key_parses_with_its_kind(): void
    {
        $key = MetricKey::tryParse('harness-events:hook_success.v2-x', new TemplateMetricSet());

        self::assertNotNull($key);
        self::assertSame(Metric::HarnessEvents, $key->metric);
        self::assertSame('hook_success.v2-x', $key->name);
        self::assertSame('harness-events:hook_success.v2-x', $key->key());
        self::assertSame($key->key(), new MetricKey(Metric::HarnessEvents, 'hook_success.v2-x')->key());
    }

    public function test_only_the_named_metrics_leave_the_standalone_list(): void
    {
        self::assertSame([Metric::BucketTime, Metric::BucketTimeAll, Metric::BucketCalls, Metric::HarnessEvents], array_values(array_filter(Metric::cases(), static fn (Metric $metric): bool => $metric->isNamed())));
        self::assertNotContains(Metric::HarnessEvents, Metric::standalone());
        self::assertNotContains(Metric::BucketTime, Metric::standalone());
    }

    public function test_a_bucket_name_of_64_characters_parses(): void
    {
        $name = str_repeat('a', 64);

        self::assertSame($name, MetricKey::tryParse('bucket-time:'.$name, new TemplateMetricSet())?->name);
    }

    public function test_a_key_of_the_template_set_parses_as_a_template_key(): void
    {
        $key = MetricKey::tryParse('merge-rate', TemplateMetricSets::all());

        self::assertNotNull($key);
        self::assertNull($key->metric);
        self::assertNull($key->name);
        self::assertSame('merge-rate', $key->templateKey);
        self::assertTrue($key->isTemplate());
        self::assertSame('merge-rate', $key->key());
    }

    public function test_a_template_key_is_unknown_to_a_project_that_does_not_declare_it(): void
    {
        self::assertNull(MetricKey::tryParse('merge-rate', new TemplateMetricSet()));
    }

    public function test_a_built_in_key_wins_over_a_template_metric_of_the_same_key(): void
    {
        $key = MetricKey::tryParse('cost', new TemplateMetricSet([TemplateMetricSets::count('cost')]));

        self::assertNotNull($key);
        self::assertSame(Metric::Cost, $key->metric);
        self::assertNull($key->templateKey);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidKeys(): iterable
    {
        yield 'unknown' => ['speed'];
        yield 'empty' => [''];
        yield 'bucket time with no name' => ['bucket-time'];
        yield 'bucket time with an empty name' => ['bucket-time:'];
        yield 'upper case name' => ['bucket-time:Tests'];
        yield 'name with a space' => ['bucket-time:a b'];
        yield 'name with a new line' => ["bucket-time:tests\n"];
        yield 'name of 65 characters' => ['bucket-time:'.str_repeat('a', 65)];
        yield 'bucket calls with no name' => ['bucket-calls'];
        yield 'bucket calls with a dot' => ['bucket-calls:a.b'];
        yield 'bucket time all with an empty name' => ['bucket-time-all:'];
        yield 'harness events with no kind' => ['harness-events'];
        yield 'harness events with an empty kind' => ['harness-events:'];
        yield 'harness events kind in upper case' => ['harness-events:Diagnostics'];
        yield 'harness events kind of 65 characters' => ['harness-events:'.str_repeat('a', 65)];
        yield 'bucket time with a dot' => ['bucket-time:a.b'];
        yield 'standalone metric with a name' => ['cost:tests'];
        yield 'template metric with a name' => ['merge-rate:tests'];
    }

    #[DataProvider('invalidKeys')]
    public function test_an_invalid_key_gives_null(string $value): void
    {
        self::assertNull(MetricKey::tryParse($value, TemplateMetricSets::all()));
    }

    public function test_the_constructor_refuses_a_key_that_the_parser_refuses(): void
    {
        $refused = [
            [Metric::BucketTime, null, null],
            [Metric::BucketTime, 'Tests', null],
            [Metric::HarnessEvents, null, null],
            [Metric::HarnessEvents, 'Tests', null],
            [Metric::BucketTime, 'a.b', null],
            [Metric::Cost, 'tests', null],
            [Metric::Cost, null, 'merge-rate'],
            [null, null, null],
            [null, null, ''],
            [null, 'tests', 'merge-rate'],
        ];
        foreach ($refused as [$metric, $name, $templateKey]) {
            try {
                new MetricKey($metric, $name, $templateKey);
                self::fail('Expected a refusal.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
