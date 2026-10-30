<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Mcp;

use App\Module\Bridge\Command\ListMetricsHandler;
use App\Module\Bridge\Command\MetricQueryHandler;
use App\Module\Bridge\Mcp\BridgeSubjectResolver;
use App\Module\Bridge\Mcp\MetricListTool;
use App\Module\Bridge\Mcp\MetricQueryTool;
use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Repository\WorkerRunBucketTimeRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\TemplateMetrics;
use App\Module\Workflow\Contract\TemplateMetricSet;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\McpTokenScenario;
use App\Tests\Support\TemplateMetricSets;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class MetricToolsTest extends KernelTestCase
{
    use BridgeScenario;
    use McpTokenScenario;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_the_list_gives_every_metric_with_its_combinations(): void
    {
        [$project] = $this->projects('metric-list');
        $this->actAsMcpTokenBoundTo($project);

        $metrics = $this->listTool()()['metrics'];

        self::assertSame([...array_map(static fn (Metric $metric): string => $metric->value, Metric::standalone()), 'merge-rate', 'hours-to-merge'], array_column($metrics, 'key'));
        $stopRate = array_column($metrics, null, 'key')['stop-rate'];
        self::assertSame([
            'key' => 'stop-rate',
            'units' => ['run'],
            'valueType' => 'ratio',
            'statistics' => ['mean', 'count'],
            'groups' => ['stage', 'model', 'variant', 'card-type', 'bridge', 'harness', 'account', 'harness-version', 'cli-version', 'skill-version', 'none'],
            'description' => Metric::StopRate->description(),
        ], $stopRate);
    }

    public function test_the_list_adds_the_metrics_of_the_workflow_template_after_the_built_in_ones(): void
    {
        [$project] = $this->projects('metric-list-template');
        $this->actAsMcpTokenBoundTo($project);

        $metrics = $this->listTool(TemplateMetricSets::all())()['metrics'];

        self::assertSame(
            [...array_map(static fn (Metric $metric): string => $metric->value, Metric::standalone()), 'merge-rate', 'fix-rounds', 'hours-to-merge'],
            array_column($metrics, 'key'),
        );
        self::assertSame([
            'label' => 'Merge rate',
            'key' => 'merge-rate',
            'units' => ['card'],
            'valueType' => 'ratio',
            'statistics' => ['mean', 'count'],
            'groups' => ['variant', 'card-type', 'none'],
            'description' => 'The share of cards that merged.',
        ], array_column($metrics, null, 'key')['merge-rate']);
        self::assertSame('duration', array_column($metrics, null, 'key')['hours-to-merge']['valueType']);
        self::assertArrayNotHasKey('label', array_column($metrics, null, 'key')['cost']);
    }

    public function test_the_query_refuses_a_combination_a_template_metric_does_not_allow(): void
    {
        [$project] = $this->projects('metric-template-refusal');
        $this->actAsMcpTokenBoundTo($project);

        try {
            $this->queryTool(TemplateMetricSets::all())(unit: 'run', metric: 'merge-rate', statistic: 'sum', group: 'stage');
            self::fail('Expected a refusal.');
        } catch (ToolCallException $e) {
            self::assertSame(
                'The metric merge-rate does not take the unit "run". Use one of: card. The metric merge-rate does not take the statistic "sum". Use one of: mean, count. The metric merge-rate does not take the group "stage". Use one of: variant, card-type, none. metric_list lists the valid combinations.',
                $e->getMessage(),
            );
        }
    }

    public function test_a_template_key_is_unknown_to_a_project_that_does_not_declare_it(): void
    {
        [$project] = $this->projects('metric-template-unknown');
        $this->actAsMcpTokenBoundTo($project);

        $message = $this->refusalWith(new TemplateMetricSet(), 'merge-rate');
        self::assertStringStartsWith('Unknown metric "merge-rate". Use one of: cost,', $message);
        self::assertStringNotContainsString('hours-to-merge', $message);
        self::assertStringContainsString('hours-to-merge', $this->refusalWith(TemplateMetricSets::all(), 'speed'));
    }

    public function test_the_list_adds_one_entry_for_each_bucket_of_the_project(): void
    {
        [$project, $other] = $this->projects('metric-list-buckets');
        $this->runWithBuckets($project, ['tests' => 10, 'other' => 5]);
        $this->runWithBuckets($project, ['tests' => 20, 'build' => 30]);
        $this->runWithBuckets($other, ['elsewhere' => 1]);
        $this->actAsMcpTokenBoundTo($project);

        $metrics = $this->listTool()()['metrics'];

        $keys = array_column($metrics, 'key');
        self::assertSame(
            ['bucket-time:build', 'bucket-time:other', 'bucket-time:tests', 'bucket-time-all:build', 'bucket-time-all:other', 'bucket-time-all:tests', 'bucket-calls:build', 'bucket-calls:other', 'bucket-calls:tests'],
            \array_slice($keys, -9),
        );
        self::assertNotContains('bucket-time', $keys);
        self::assertNotContains('bucket-time:elsewhere', $keys);
        self::assertSame([
            'key' => 'bucket-time:build',
            'units' => ['run', 'card'],
            'valueType' => 'duration',
            'statistics' => ['median', 'mean', 'sum', 'p90', 'count'],
            'groups' => ['stage', 'model', 'variant', 'card-type', 'bridge', 'harness', 'account', 'harness-version', 'cli-version', 'skill-version', 'none'],
            'description' => Metric::BucketTime->description(),
        ], array_column($metrics, null, 'key')['bucket-time:build']);
        $calls = array_column($metrics, null, 'key')['bucket-calls:build'];
        self::assertSame('count', $calls['valueType']);
        self::assertSame(Metric::BucketCalls->description(), $calls['description']);
        self::assertSame('duration', array_column($metrics, null, 'key')['bucket-time-all:build']['valueType']);
    }

    public function test_the_bucket_calls_and_the_time_with_subagents_of_a_run_are_zero_or_unknown(): void
    {
        [$project] = $this->projects('metric-bucket-calls');
        $ended = new \DateTimeImmutable('-2 days')->setTime(9, 0);
        $inBucket = $this->fact($project, $ended, 1, runId: $this->runWithBuckets($project, ['tests' => 4000, 'other' => 1000], true));
        $otherBucket = $this->fact($project, $ended->modify('-1 hour'), 1, runId: $this->runWithBuckets($project, ['other' => 700], true));
        $oldRows = $this->fact($project, $ended->modify('-2 hours'), 1, runId: $this->runWithBuckets($project, ['tests' => 900]));
        $noRows = $this->fact($project, $ended->modify('-3 hours'), 1);
        $this->actAsMcpTokenBoundTo($project);

        $calls = $this->queryTool()(unit: 'run', metric: 'bucket-calls:tests', statistic: 'sum');
        $all = $this->queryTool()(unit: 'run', metric: 'bucket-time-all:tests', statistic: 'sum');

        self::assertSame([(string) $inBucket => 3, (string) $otherBucket => 0, (string) $oldRows => null, (string) $noRows => null], array_column($calls['series'][0]['rows'], 'value', 'id'));
        self::assertSame([(string) $inBucket => 8000, (string) $otherBucket => 0, (string) $oldRows => null, (string) $noRows => null], array_column($all['series'][0]['rows'], 'value', 'id'));
        self::assertSame(['value' => 3, 'rows' => 2], $calls['series'][0]['total']);
    }

    public function test_the_list_adds_one_entry_for_each_harness_event_kind_largest_total_first(): void
    {
        [$project, $other] = $this->projects('metric-list-events');
        $this->runWithEvents($project, ['diagnostics' => 3, 'hook_success' => 10]);
        $this->runWithEvents($project, ['diagnostics' => 4, 'queued_command' => 7]);
        $this->runWithEvents($project, []);
        $this->runWithEvents($project, null);
        $this->runWithEvents($other, ['elsewhere' => 99]);
        $this->actAsMcpTokenBoundTo($project);

        $metrics = $this->listTool()()['metrics'];

        $keys = array_column($metrics, 'key');
        self::assertSame(['harness-events:hook_success', 'harness-events:diagnostics', 'harness-events:queued_command'], \array_slice($keys, -3));
        self::assertNotContains('harness-events', $keys);
        self::assertSame([
            'key' => 'harness-events:diagnostics',
            'units' => ['run', 'card'],
            'valueType' => 'count',
            'statistics' => ['median', 'mean', 'sum', 'p90', 'count'],
            'groups' => ['stage', 'model', 'variant', 'card-type', 'bridge', 'harness', 'account', 'harness-version', 'cli-version', 'skill-version', 'none'],
            'description' => Metric::HarnessEvents->description(),
        ], array_column($metrics, null, 'key')['harness-events:diagnostics']);
    }

    public function test_a_harness_events_run_has_the_count_zero_or_unknown(): void
    {
        [$project] = $this->projects('metric-events-run');
        $ended = new \DateTimeImmutable('-2 days')->setTime(9, 0);
        $counted = $this->fact($project, $ended, 1, runId: $this->runWithEvents($project, ['diagnostics' => 4, 'other' => 1]));
        $missingKind = $this->fact($project, $ended->modify('-1 hour'), 1, runId: $this->runWithEvents($project, ['other' => 2]));
        $empty = $this->fact($project, $ended->modify('-2 hours'), 1, runId: $this->runWithEvents($project, []));
        $noMap = $this->fact($project, $ended->modify('-3 hours'), 1, runId: $this->runWithEvents($project, null));
        $this->actAsMcpTokenBoundTo($project);

        $result = $this->queryTool()(unit: 'run', metric: 'harness-events:diagnostics', statistic: 'sum');

        self::assertSame('harness-events:diagnostics', $result['metric']);
        $series = $result['series'][0];
        self::assertSame(['value' => 4, 'rows' => 3], $series['total']);
        self::assertSame(
            [(string) $counted => 4, (string) $missingKind => 0, (string) $empty => 0, (string) $noMap => null],
            array_column($series['rows'], 'value', 'id'),
        );
    }

    public function test_a_harness_events_metric_without_a_valid_kind_is_refused(): void
    {
        [$project] = $this->projects('metric-events-name');
        $this->actAsMcpTokenBoundTo($project);

        foreach (['harness-events', 'harness-events:', 'harness-events:Diagnostics', 'harness-events:a b', 'harness-events:'.str_repeat('a', 65)] as $metric) {
            self::assertStringContainsString('needs the name of a harness event kind', $this->refusal(unit: 'run', metric: $metric, statistic: 'sum'), $metric);
        }
    }

    public function test_the_list_refuses_an_unbound_token(): void
    {
        [$project] = $this->projects('metric-list-unbound');
        $this->actAsUnboundMcpToken($project->owner);

        $this->expectException(ToolCallException::class);

        $this->listTool()();
    }

    public function test_the_query_reads_the_runs_of_the_bound_project_with_the_defaults(): void
    {
        [$project, $other] = $this->projects('metric-query');
        $ended = new \DateTimeImmutable('-2 days')->setTime(9, 0);
        $older = $this->fact($project, $ended->modify('-1 hour'), 1_500_000, cardNumber: 3);
        $newer = $this->fact($project, $ended, 500_000, cardNumber: 4);
        $this->fact($project, new \DateTimeImmutable('-40 days'), 9_000_000);
        $this->fact($other, $ended, 7_000_000);
        $this->actAsMcpTokenBoundTo($project);

        $result = $this->queryTool()(unit: 'run', metric: 'cost', statistic: 'sum');

        self::assertSame(['run', 'cost', 'sum', 'none', 'thirty-days', 'week'], [$result['unit'], $result['metric'], $result['statistic'], $result['group'], $result['range'], $result['bucket']]);
        self::assertCount(1, $result['series']);
        $series = $result['series'][0];
        self::assertNull($series['group']);
        self::assertSame(['value' => 2.0, 'rows' => 2], $series['total']);
        self::assertSame([['start' => MetricBucket::Week->startOf($ended)->format(\DATE_ATOM), 'value' => 2.0, 'rows' => 2]], $series['points']);
        self::assertSame(2, $series['rowsTotal']);
        self::assertSame([
            ['id' => (string) $newer, 'cardNumber' => 4, 'time' => $ended->format(\DATE_ATOM), 'value' => 0.5],
            ['id' => (string) $older, 'cardNumber' => 3, 'time' => $ended->modify('-1 hour')->format(\DATE_ATOM), 'value' => 1.5],
        ], $series['rows']);
    }

    public function test_a_query_with_no_compare_has_no_comparison_and_one_window(): void
    {
        [$project] = $this->projects('metric-no-compare');
        $this->fact($project, new \DateTimeImmutable('-2 days'), 1_000_000);
        $this->actAsMcpTokenBoundTo($project);

        $result = $this->queryTool()(unit: 'run', metric: 'cost', statistic: 'sum');

        self::assertSame('off', $result['compare']);
        self::assertNull($result['window']['previous']);
        self::assertNotNull($result['window']['current']['from']);
        self::assertArrayNotHasKey('comparison', $result['series'][0]);
    }

    public function test_a_compare_adds_the_windows_and_the_comparison_of_each_series(): void
    {
        [$project] = $this->projects('metric-compare');
        $this->fact($project, new \DateTimeImmutable('-2 days'), 3_000_000, cardNumber: 1);
        $this->fact($project, new \DateTimeImmutable('-40 days'), 2_000_000, cardNumber: 2);
        $this->actAsMcpTokenBoundTo($project);

        $result = $this->queryTool()(unit: 'run', metric: 'cost', statistic: 'sum', compare: 'previous');

        self::assertSame('previous', $result['compare']);
        $previousWindow = $result['window']['previous'];
        self::assertNotNull($previousWindow);
        self::assertSame($result['window']['current']['from'], $previousWindow['to']);
        $comparison = $result['series'][0]['comparison'] ?? null;
        self::assertNotNull($comparison);
        self::assertSame(['value' => 2.0, 'rows' => 1], $comparison['previous']);
        self::assertSame(['value' => 0.5, 'kind' => 'relative'], $comparison['change']);
        self::assertNull($comparison['sign']);
        self::assertNull($comparison['likelyRange']);
    }

    public function test_a_compare_of_a_mean_gives_the_ranges_and_a_sign(): void
    {
        [$project] = $this->projects('metric-compare-mean');
        for ($i = 0; $i < 6; ++$i) {
            $this->fact($project, new \DateTimeImmutable('-2 days'), (10 + $i) * 100_000, cardNumber: $i + 1);
            $this->fact($project, new \DateTimeImmutable('-40 days'), (50 + $i) * 100_000, cardNumber: $i + 10);
        }
        $this->actAsMcpTokenBoundTo($project);

        $comparison = $this->queryTool()(unit: 'run', metric: 'cost', statistic: 'mean', compare: 'previous')['series'][0]['comparison'] ?? null;

        self::assertNotNull($comparison);
        self::assertSame('clear', $comparison['sign']);
        $ranges = $comparison['likelyRange'];
        self::assertNotNull($ranges);
        self::assertNotNull($ranges['current']);
        self::assertNotNull($ranges['previous']);
        self::assertLessThan($ranges['previous']['low'], $ranges['current']['high']);
        self::assertNotNull($ranges['change']);
        self::assertNotNull($comparison['change']);
        self::assertLessThan(0, $comparison['change']['value']);
    }

    public function test_the_default_range_stays_thirty_days_and_seven_days_take_day_alone(): void
    {
        [$project] = $this->projects('metric-seven-days');
        $this->fact($project, new \DateTimeImmutable('-2 days'), 1_000_000);
        $this->actAsMcpTokenBoundTo($project);

        self::assertSame('thirty-days', $this->queryTool()(unit: 'run', metric: 'cost', statistic: 'sum')['range']);
        self::assertSame('seven-days', $this->queryTool()(unit: 'run', metric: 'cost', statistic: 'sum', range: 'seven-days', bucket: 'day')['range']);
        self::assertSame(
            'The range seven-days does not take the bucket "week". Use one of: day. metric_list lists the valid combinations.',
            $this->refusal(unit: 'run', metric: 'cost', statistic: 'sum', range: 'seven-days'),
        );
    }

    public function test_a_compare_of_all_time_is_refused(): void
    {
        [$project] = $this->projects('metric-compare-all');
        $this->actAsMcpTokenBoundTo($project);

        self::assertSame(
            'The range all has no previous period to compare with. Use compare off, or another range. metric_list lists the valid combinations.',
            $this->refusal(unit: 'run', metric: 'cost', statistic: 'sum', range: 'all', compare: 'previous'),
        );
        self::assertSame(
            'Unknown compare "later". Use one of: off, previous.',
            $this->refusal(unit: 'run', metric: 'cost', statistic: 'sum', compare: 'later'),
        );
    }

    public function test_the_query_gives_at_most_100_rows_per_series_newest_first(): void
    {
        [$project] = $this->projects('metric-cap');
        $start = new \DateTimeImmutable('-10 days');
        for ($i = 0; $i < 101; ++$i) {
            $this->fact($project, $start->modify(\sprintf('+%d minutes', $i)), 1_000);
        }
        $this->actAsMcpTokenBoundTo($project);

        $series = $this->queryTool()(unit: 'run', metric: 'duration', statistic: 'count', range: 'all', bucket: 'day')['series'][0];

        self::assertCount(100, $series['rows']);
        self::assertSame(101, $series['rowsTotal']);
        self::assertSame(['value' => 101, 'rows' => 101], $series['total']);
        self::assertGreaterThan($series['rows'][1]['time'], $series['rows'][0]['time']);
    }

    public function test_an_unknown_value_is_refused_with_the_valid_values(): void
    {
        [$project] = $this->projects('metric-unknown');
        $this->actAsMcpTokenBoundTo($project);

        self::assertSame(
            'Unknown metric "speed". Use one of: cost, input-tokens, output-tokens, cache-read-tokens, cache-write-tokens, duration, runs, stop-rate, bucket-time-each, bucket-time-all-each, bucket-calls-each, merge-rate, hours-to-merge, bucket-time:<name>, bucket-time-all:<name>, bucket-calls:<name>, harness-events:<kind>.',
            $this->refusal(unit: 'run', metric: 'speed', statistic: 'sum'),
        );
        self::assertSame(
            'Unknown bucket "year". Use one of: day, week, month.',
            $this->refusal(unit: 'run', metric: 'cost', statistic: 'sum', bucket: 'year'),
        );
    }

    public function test_a_refused_combination_names_the_field_and_the_allowed_values(): void
    {
        [$project] = $this->projects('metric-combination');
        $this->actAsMcpTokenBoundTo($project);

        self::assertSame(
            'The metric stop-rate does not take the unit "card". Use one of: run. The metric stop-rate does not take the statistic "sum". Use one of: mean, count. metric_list lists the valid combinations.',
            $this->refusal(unit: 'card', metric: 'stop-rate', statistic: 'sum'),
        );
    }

    public function test_a_bucket_time_run_has_the_time_of_the_bucket_zero_or_unknown(): void
    {
        [$project] = $this->projects('metric-bucket-run');
        $ended = new \DateTimeImmutable('-2 days')->setTime(9, 0);
        $inBucket = $this->fact($project, $ended, 1, runId: $this->runWithBuckets($project, ['tests' => 4000, 'other' => 1000]));
        $otherBucket = $this->fact($project, $ended->modify('-1 hour'), 1, runId: $this->runWithBuckets($project, ['other' => 700]));
        $noRows = $this->fact($project, $ended->modify('-2 hours'), 1);
        $this->actAsMcpTokenBoundTo($project);

        $result = $this->queryTool()(unit: 'run', metric: 'bucket-time:tests', statistic: 'sum');

        self::assertSame('bucket-time:tests', $result['metric']);
        $series = $result['series'][0];
        self::assertSame(['value' => 4000, 'rows' => 2], $series['total']);
        $values = array_column($series['rows'], 'value', 'id');
        self::assertSame([(string) $inBucket => 4000, (string) $otherBucket => 0, (string) $noRows => null], $values);
    }

    public function test_a_bucket_time_without_a_valid_name_is_refused(): void
    {
        [$project] = $this->projects('metric-bucket-name');
        $this->actAsMcpTokenBoundTo($project);

        foreach (['bucket-time', 'bucket-time:', 'bucket-time:Tests', 'bucket-time:a b', 'bucket-time:'.str_repeat('a', 65), 'bucket-calls', 'bucket-calls:Tests', 'bucket-time-all:', 'bucket-time-all:a.b'] as $metric) {
            self::assertStringContainsString('needs the name of a bucket', $this->refusal(unit: 'run', metric: $metric, statistic: 'sum'), $metric);
        }
        self::assertStringContainsString('Unknown metric "cost:tests"', $this->refusal(unit: 'run', metric: 'cost:tests', statistic: 'sum'));
    }

    public function test_a_bucket_time_name_of_64_characters_is_read(): void
    {
        [$project] = $this->projects('metric-bucket-long');
        $name = str_repeat('a', 64);
        $this->fact($project, new \DateTimeImmutable('-1 day'), 1, runId: $this->runWithBuckets($project, [$name => 5]));
        $this->actAsMcpTokenBoundTo($project);

        $result = $this->queryTool()(unit: 'run', metric: 'bucket-time:'.$name, statistic: 'sum');

        self::assertSame(['value' => 5, 'rows' => 1], $result['series'][0]['total']);
    }

    public function test_the_query_refuses_an_unbound_token(): void
    {
        [$project] = $this->projects('metric-unbound');
        $this->actAsUnboundMcpToken($project->owner);

        $this->expectException(ToolCallException::class);

        $this->queryTool()(unit: 'run', metric: 'cost', statistic: 'sum');
    }

    /** @return array{Project, Project} two projects of one owner */
    private function projects(string $name): array
    {
        $em = $this->em();
        $owner = $this->user($em, 'mcp-'.$name.'-'.uniqid().'@example.com');

        return [$this->project($em, $owner, 'Bound '.$name), $this->project($em, $owner, 'Other '.$name)];
    }

    /**
     * @param array<string, int> $times
     *
     * @return Uuid the id of a stored run of the project that has the bucket rows
     */
    private function runWithBuckets(Project $project, array $times, bool $withFigures = false): Uuid
    {
        $run = $this->seedRun($this->em(), $project);
        // The fact listener wrote a fact row for the run, and the test writes its own.
        $this->em()->getConnection()->executeStatement('DELETE FROM bridge_worker_run_facts WHERE run_id = :run', ['run' => (string) $run->id]);
        foreach ($times as $bucket => $ms) {
            $this->em()->getConnection()->insert('bridge_worker_run_bucket_times', ['id' => (string) Uuid::v7(), 'run_id' => (string) $run->id, 'bucket' => (string) $bucket, 'ms' => $ms] + ($withFigures ? ['ms_with_subagents' => $ms * 2, 'calls' => 3] : []));
        }

        return $run->id ?? throw new \LogicException('A stored run has an id.');
    }

    /**
     * @param array<string, int>|null $events
     *
     * @return Uuid the id of a stored run of the project that holds the harness events
     */
    private function runWithEvents(Project $project, ?array $events): Uuid
    {
        $run = $this->seedRun($this->em(), $project);
        $run->harnessEvents = $events;
        $this->em()->flush();
        $this->em()->getConnection()->executeStatement('DELETE FROM bridge_worker_run_facts WHERE run_id = :run', ['run' => (string) $run->id]);

        return $run->id ?? throw new \LogicException('A stored run has an id.');
    }

    private function fact(Project $project, \DateTimeImmutable $endedAt, int $cost, ?int $cardNumber = null, ?Uuid $runId = null): Uuid
    {
        $runId ??= Uuid::v7();
        $this->em()->getConnection()->insert('bridge_worker_run_facts', [
            'run_id' => (string) $runId,
            'project_id' => (string) $project->id,
            'subject_type' => 'card',
            'subject_id' => (string) Uuid::v7(),
            'card_number' => $cardNumber,
            'kind' => 'worker',
            'work_kind' => 'implement',
            'outcome' => 'succeeded',
            'started_at' => $endedAt->modify('-5 minutes')->format('Y-m-d H:i:s'),
            'ended_at' => $endedAt->format('Y-m-d H:i:s'),
            'received_at' => $endedAt->format('Y-m-d H:i:s'),
            'duration_ms' => 300_000,
            'cost_micro_usd' => $cost,
            'usage_source' => 'reported',
        ]);

        return $runId;
    }

    private function refusal(string $unit, string $metric, string $statistic, string $bucket = 'week', string $range = 'thirty-days', string $compare = 'off'): string
    {
        try {
            $this->queryTool()(unit: $unit, metric: $metric, statistic: $statistic, range: $range, bucket: $bucket, compare: $compare);
        } catch (ToolCallException $e) {
            return $e->getMessage();
        }

        self::fail('Expected a refusal.');
    }

    private function refusalWith(TemplateMetricSet $templates, string $metric): string
    {
        try {
            $this->queryTool($templates)(unit: 'card', metric: $metric, statistic: 'sum');
        } catch (ToolCallException $e) {
            return $e->getMessage();
        }

        self::fail('Expected a refusal.');
    }

    private function listTool(?TemplateMetricSet $templates = null): MetricListTool
    {
        if (null === $templates) {
            $tool = self::getContainer()->get(MetricListTool::class);
            self::assertInstanceOf(MetricListTool::class, $tool);

            return $tool;
        }

        $subjects = self::getContainer()->get(BridgeSubjectResolver::class);
        self::assertInstanceOf(BridgeSubjectResolver::class, $subjects);
        $buckets = self::getContainer()->get(WorkerRunBucketTimeRepository::class);
        self::assertInstanceOf(WorkerRunBucketTimeRepository::class, $buckets);
        $runs = self::getContainer()->get(WorkerRunRepository::class);
        self::assertInstanceOf(WorkerRunRepository::class, $runs);

        return new MetricListTool($subjects, new ListMetricsHandler($buckets, $runs, self::templateMetrics($templates)));
    }

    private function queryTool(?TemplateMetricSet $templates = null): MetricQueryTool
    {
        if (null === $templates) {
            $tool = self::getContainer()->get(MetricQueryTool::class);
            self::assertInstanceOf(MetricQueryTool::class, $tool);

            return $tool;
        }

        $subjects = self::getContainer()->get(BridgeSubjectResolver::class);
        self::assertInstanceOf(BridgeSubjectResolver::class, $subjects);
        $handler = self::getContainer()->get(MetricQueryHandler::class);
        self::assertInstanceOf(MetricQueryHandler::class, $handler);

        return new MetricQueryTool($subjects, $handler, self::templateMetrics($templates));
    }

    private static function templateMetrics(TemplateMetricSet $set): TemplateMetrics
    {
        return new readonly class($set) implements TemplateMetrics {
            public function __construct(
                private TemplateMetricSet $set,
            ) {
            }

            #[\Override]
            public function forProject(Uuid $projectId): TemplateMetricSet
            {
                return $this->set;
            }
        };
    }
}
