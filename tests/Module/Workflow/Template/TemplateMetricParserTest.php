<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Template;

use App\Module\Board\Workflow\MoveCard;
use App\Module\Board\Workflow\RequestWork;
use App\Module\Bridge\Metric\BuiltInMetricKeys;
use App\Module\Workflow\Action\Actions;
use App\Module\Workflow\Condition\CardInSlot;
use App\Module\Workflow\Condition\Conditions;
use App\Module\Workflow\Contract\Action;
use App\Module\Workflow\Contract\CountFilter;
use App\Module\Workflow\Contract\DurationEvent;
use App\Module\Workflow\Contract\MetricType;
use App\Module\Workflow\Contract\TemplateMetric;
use App\Module\Workflow\Contract\TemplateOutcome;
use App\Module\Workflow\Template\InvalidTemplate;
use App\Module\Workflow\Template\TemplateMetricParser;
use App\Module\Workflow\Template\TemplateParser;
use App\Module\Workflow\Template\TemplatePrompts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TemplateMetricParserTest extends TestCase
{
    private TemplateParser $parser;

    #[\Override]
    protected function setUp(): void
    {
        $this->parser = new TemplateParser(
            new Conditions([new CardInSlot()]),
            new Actions(array_map(
                static fn (string $class): Action => new \ReflectionClass($class)->newInstanceWithoutConstructor(),
                [MoveCard::class, RequestWork::class],
            )),
            new TemplatePrompts(__DIR__.'/Fixtures/template-prompts'),
            new TemplateMetricParser(new BuiltInMetricKeys()),
        );
    }

    /** @return array<string, mixed> a template with a review request, a fix request with a reason, a move that sets an outcome, and no metric */
    private static function valid(): array
    {
        return [
            'key' => 'test',
            'version' => 1,
            'defaultType' => 'feature',
            'types' => [['key' => 'feature', 'label' => 'board.card.type.feature', 'tone' => 'lime']],
            'slots' => [['key' => 'build', 'label' => 'workflow.slot.build']],
            'manualMoves' => [],
            'backoffMinutes' => [10],
            'outcomes' => ['merged', ['key' => 'dropped']],
            'rules' => [
                ['id' => 'review', 'slot' => 'build', 'when' => ['card.in_slot' => ['slot' => 'build']], 'then' => ['request' => ['kind' => 'review']]],
                ['id' => 'fix', 'slot' => 'build', 'when' => ['card.in_slot' => ['slot' => 'build']], 'then' => ['request' => ['kind' => 'fix', 'role' => 'fix', 'reason' => 'agent-review']]],
                ['id' => 'finish', 'slot' => 'build', 'when' => ['card.in_slot' => ['slot' => 'build']], 'then' => ['move' => ['to' => '@terminal', 'outcome' => 'merged']]],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $metric
     *
     * @return array<string, mixed>
     */
    private static function withMetric(array $metric): array
    {
        $metric += ['key' => 'my-metric', 'label' => 'My metric', 'description' => 'What it measures.'];

        return ['metrics' => [$metric]] + self::valid();
    }

    /**
     * @param array<string, mixed> $source
     *
     * @return list<string>
     */
    private function errorsOf(array $source): array
    {
        try {
            $this->parser->parse($source);
        } catch (InvalidTemplate $e) {
            return $e->errors;
        }
        self::fail('The template should be refused.');
    }

    public function test_a_template_with_no_outcomes_or_metrics_declares_none(): void
    {
        $source = self::valid();
        unset($source['outcomes']);
        $source['rules'][2]['then']['move'] = ['to' => '@terminal'];

        $template = $this->parser->parse($source);

        self::assertSame([], $template->outcomes);
        self::assertSame([], $template->metrics);
    }

    public function test_it_reads_the_outcomes_with_a_delivered_one(): void
    {
        $source = self::valid();
        $source['outcomes'] = [['key' => 'merged', 'delivered' => true], 'dropped'];

        self::assertEquals([new TemplateOutcome('merged', true), new TemplateOutcome('dropped')], $this->parser->parse($source)->outcomes);
    }

    #[DataProvider('invalidOutcomes')]
    public function test_it_refuses_a_bad_outcomes_list(mixed $outcomes, string $error): void
    {
        $source = ['outcomes' => $outcomes] + self::valid();
        $source['rules'][2]['then']['move'] = ['to' => '@terminal'];

        self::assertContains($error, $this->errorsOf($source));
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function invalidOutcomes(): iterable
    {
        yield 'a map' => [['merged' => true], 'outcomes: must be a list'];
        yield 'a bad key' => [['Merged'], 'outcomes[0]: an outcome must be a key of lowercase words joined with hyphens, or a map with a "key"'];
        yield 'a duplicate' => [['merged', 'merged'], 'outcomes[1]: duplicate outcome "merged"'];
        yield 'two delivered' => [[['key' => 'a', 'delivered' => true], ['key' => 'b', 'delivered' => true]], 'outcomes[1]: only one outcome may be delivered'];
        yield 'delivered is not a boolean' => [[['key' => 'a', 'delivered' => 'yes']], 'outcomes[0] delivered: must be true or false'];
        yield 'an unknown key' => [[['key' => 'a', 'label' => 'A']], 'outcomes[0]: unknown key "label"'];
    }

    public function test_a_move_must_set_a_declared_outcome(): void
    {
        $source = self::valid();
        $source['rules'][2]['then']['move']['outcome'] = 'shipped';

        self::assertContains('rules (finish): unknown outcome "shipped"; known outcomes: merged, dropped', $this->errorsOf($source));
    }

    public function test_a_move_that_sets_an_outcome_needs_a_template_that_declares_outcomes(): void
    {
        $source = self::valid();
        unset($source['outcomes']);

        self::assertContains('rules (finish): unknown outcome "merged"; the template declares no outcomes', $this->errorsOf($source));
    }

    public function test_a_stored_copy_keeps_a_move_whose_outcome_it_does_not_declare(): void
    {
        $source = self::valid();
        unset($source['outcomes']);

        self::assertSame('merged', $this->parser->parseStored($source)->rules[2]->lead->params['outcome']);
    }

    public function test_it_reads_a_count(): void
    {
        $template = $this->parser->parse(self::withMetric(['count' => ['role' => 'fix'], 'splitBy' => 'reason']));

        self::assertEquals([new TemplateMetric('my-metric', 'My metric', 'What it measures.', MetricType::Count, new CountFilter(role: 'fix'), 'reason')], $template->metrics);
    }

    public function test_a_count_reads_the_reasons_of_a_pull_request_and_unknown_for_a_request_with_no_reason(): void
    {
        $template = $this->parser->parse(self::withMetric(['count' => ['reason' => 'checks-failed']]));
        self::assertEquals(new CountFilter(reason: 'checks-failed'), $template->metrics[0]->filter);

        $template = $this->parser->parse(self::withMetric(['count' => ['kind' => 'review', 'reason' => 'unknown']]));
        self::assertEquals(new CountFilter(kind: 'review', reason: 'unknown'), $template->metrics[0]->filter);
    }

    public function test_a_mixed_filter_reads_a_combination_that_one_request_writes(): void
    {
        $template = $this->parser->parse(self::withMetric(['count' => ['role' => 'fix', 'kind' => 'fix', 'reason' => 'agent-review']]));
        self::assertEquals(new CountFilter(role: 'fix', kind: 'fix', reason: 'agent-review'), $template->metrics[0]->filter);
    }

    public function test_a_template_whose_requests_all_set_a_reason_knows_no_pull_request_reason(): void
    {
        $source = self::withMetric(['count' => ['reason' => 'checks-failed']]);
        $source['rules'] = array_values(array_filter($source['rules'], static fn (array $rule): bool => 'review' !== $rule['id']));

        self::assertContains('metrics[0] (my-metric) count reason: unknown reason "checks-failed"; known reasons: agent-review', $this->errorsOf($source));
    }

    public function test_the_repair_request_takes_any_known_reason(): void
    {
        $source = self::withMetric(['count' => ['kind' => 'repair', 'reason' => 'agent-review']]);
        $source['onWorkFailed'] = ['retryOn' => ['no-taker'], 'retries' => 1, 'backoffMinutes' => [5], 'repair' => ['kind' => 'repair']];

        self::assertEquals(new CountFilter(kind: 'repair', reason: 'agent-review'), $this->parser->parse($source)->metrics[0]->filter);
    }

    public function test_it_reads_both_rate_forms(): void
    {
        $outcome = $this->parser->parse(self::withMetric(['rate' => ['outcome' => 'merged']]))->metrics[0];
        $any = $this->parser->parse(self::withMetric(['rate' => ['any' => ['role' => 'fix']]]))->metrics[0];

        self::assertSame(MetricType::Rate, $outcome->type);
        self::assertSame('merged', $outcome->outcome);
        self::assertNull($outcome->filter);
        self::assertEquals(new CountFilter(role: 'fix'), $any->filter);
        self::assertNull($any->outcome);
    }

    public function test_it_reads_durations(): void
    {
        $metric = $this->parser->parse(self::withMetric(['duration' => [
            'from' => ['last' => 'agent-review-passed'],
            'to' => ['arrived' => '@terminal'],
        ]]))->metrics[0];

        self::assertEquals(new DurationEvent('agent-review-passed'), $metric->from);
        self::assertTrue($metric->fromLast);
        self::assertEquals(new DurationEvent('arrived', '@terminal'), $metric->to);

        $metric = $this->parser->parse(self::withMetric(['duration' => ['from' => ['left' => 'build'], 'to' => 'pr-merged']]))->metrics[0];
        self::assertEquals(new DurationEvent('left', 'build'), $metric->from);
        self::assertFalse($metric->fromLast);
    }

    /** @param array<string, mixed> $metric */
    #[DataProvider('invalidMetrics')]
    public function test_it_refuses_a_bad_metric(array $metric, string $error): void
    {
        self::assertContains($error, $this->errorsOf(self::withMetric($metric)));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidMetrics(): iterable
    {
        yield 'no type' => [[], 'metrics[0] (my-metric): must have exactly one of count, rate and duration'];
        yield 'two types' => [['count' => ['role' => 'fix'], 'rate' => ['outcome' => 'merged']], 'metrics[0] (my-metric): must have exactly one of count, rate and duration'];
        yield 'a built-in key' => [['key' => 'cost', 'count' => ['role' => 'fix']], 'metrics[0] (cost): the key "cost" belongs to a metric the app computes'];
        yield 'a bad key' => [['key' => 'Fix Rounds', 'count' => ['role' => 'fix']], 'metrics[0] key: must be lowercase words joined with hyphens, at most 64 characters'];
        yield 'no label' => [['label' => '', 'count' => ['role' => 'fix']], 'metrics[0] (my-metric) label: must be a non-empty string of at most 80 characters'];
        yield 'no description' => [['description' => 7, 'count' => ['role' => 'fix']], 'metrics[0] (my-metric) description: must be a non-empty string of at most 300 characters'];
        yield 'an unknown key' => [['colour' => 'red', 'count' => ['role' => 'fix']], 'metrics[0] (my-metric): unknown key "colour"'];
        yield 'an unknown role' => [['count' => ['role' => 'builder']], 'metrics[0] (my-metric) count role: unknown role "builder"; known roles: fix'];
        yield 'an unknown kind' => [['count' => ['kind' => 'deploy']], 'metrics[0] (my-metric) count kind: unknown kind "deploy"; known kinds: review, fix'];
        yield 'an unknown reason' => [['count' => ['reason' => 'flaky']], 'metrics[0] (my-metric) count reason: unknown reason "flaky"; known reasons: conflict, checks-failed, changes-requested, unknown, agent-review'];
        yield 'a role and a kind that no request writes together' => [['count' => ['role' => 'fix', 'kind' => 'review']], 'metrics[0] (my-metric) count: no request of the template writes role "fix", kind "review" together'];
        yield 'a kind and a reason that no request writes together' => [['count' => ['kind' => 'review', 'reason' => 'agent-review']], 'metrics[0] (my-metric) count: no request of the template writes kind "review", reason "agent-review" together'];
        yield 'a fixed reason with a pull request reason' => [['count' => ['role' => 'fix', 'reason' => 'checks-failed']], 'metrics[0] (my-metric) count: no request of the template writes role "fix", reason "checks-failed" together'];
        yield 'a rate over a mix that no request writes' => [['rate' => ['any' => ['role' => 'fix', 'kind' => 'review']]], 'metrics[0] (my-metric) rate any: no request of the template writes role "fix", kind "review" together'];
        yield 'an unknown filter field' => [['count' => ['colour' => 'red']], 'metrics[0] (my-metric) count: unknown key "colour"'];
        yield 'an empty filter' => [['count' => []], 'metrics[0] (my-metric) count: must be a map with at least one of role, kind, reason'];
        yield 'splitBy on a rate' => [['rate' => ['outcome' => 'merged'], 'splitBy' => 'reason'], 'metrics[0] (my-metric) splitBy: only a count takes it, and it must be one of role, kind, reason'];
        yield 'an unknown splitBy' => [['count' => ['role' => 'fix'], 'splitBy' => 'colour'], 'metrics[0] (my-metric) splitBy: only a count takes it, and it must be one of role, kind, reason'];
        yield 'an unknown rate outcome' => [['rate' => ['outcome' => 'shipped']], 'metrics[0] (my-metric) rate outcome: unknown outcome "shipped"; known outcomes: merged, dropped'];
        yield 'a rate outcome that no move sets' => [['rate' => ['outcome' => 'dropped']], 'metrics[0] (my-metric) rate outcome: no move sets the outcome "dropped"; moves set: merged'];
        yield 'a rate with both forms' => [['rate' => ['outcome' => 'merged', 'any' => ['role' => 'fix']]], 'metrics[0] (my-metric) rate: must be a map with exactly one of outcome and any'];
        yield 'a duration with no end' => [['duration' => ['from' => 'pr-opened']], 'metrics[0] (my-metric) duration: must be a map with "from" and "to"'];
        yield 'an unknown event' => [['duration' => ['from' => 'pr-closed', 'to' => 'pr-merged']], 'metrics[0] (my-metric) duration from: must be one of pr-opened, agent-review-passed, ready-to-merge, pr-merged, or a map with "arrived" or "left" and a slot'];
        yield 'an unknown slot' => [['duration' => ['from' => ['arrived' => 'in-review'], 'to' => 'pr-merged']], 'metrics[0] (my-metric) duration from: unknown slot "in-review"; known slots: build, @backlog, @terminal'];
    }

    public function test_two_metrics_may_not_share_a_key(): void
    {
        $source = self::withMetric(['count' => ['role' => 'fix']]);
        $source['metrics'][] = $source['metrics'][0];

        self::assertContains('metrics[1]: duplicate metric key "my-metric"', $this->errorsOf($source));
    }

    public function test_a_review_event_needs_a_review_request(): void
    {
        $source = self::withMetric(['duration' => ['from' => ['last' => 'agent-review-passed'], 'to' => 'ready-to-merge']]);
        $source['rules'] = [$source['rules'][1], $source['rules'][2]];

        self::assertContains('metrics[0] (my-metric) duration from: the event "agent-review-passed" never happens, because no rule asks for a request of the kind "review"', $this->errorsOf($source));
    }

    public function test_the_repair_kind_counts_as_a_kind(): void
    {
        $source = self::withMetric(['count' => ['kind' => 'repair']]);
        $source['onWorkFailed'] = ['retryOn' => ['no-taker'], 'retries' => 1, 'backoffMinutes' => [5], 'repair' => ['kind' => 'repair']];

        self::assertSame('repair', $this->parser->parse($source)->metrics[0]->filter?->kind);
    }

    public function test_a_stored_copy_drops_a_metric_with_an_error_and_keeps_the_others(): void
    {
        $source = self::withMetric(['count' => ['role' => 'builder']]);
        $source['metrics'][] = ['key' => 'fix-rounds-2', 'label' => 'Fix rounds', 'description' => 'Fixes.', 'count' => ['role' => 'fix']];

        $template = $this->parser->parseStored($source);

        self::assertSame(['fix-rounds-2'], array_map(static fn (TemplateMetric $metric): string => $metric->key, $template->metrics));
    }
}
