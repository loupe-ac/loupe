<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Exception\DomainErrors;
use App\Module\Bridge\Command\MetricQueryCommand;
use App\Module\Bridge\Command\MetricQueryHandler;
use App\Module\Bridge\Command\MetricQueryView;
use App\Module\Bridge\Cost\FinishedCard;
use App\Module\Bridge\Cost\FinishedCardSourceInterface;
use App\Module\Bridge\Experiment\CardReportSourceInterface;
use App\Module\Bridge\Metric\CardMilestone;
use App\Module\Bridge\Metric\CardMilestoneSourceInterface;
use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricChangeKind;
use App\Module\Bridge\Metric\MetricCompare;
use App\Module\Bridge\Metric\MetricGroup;
use App\Module\Bridge\Metric\MetricKey;
use App\Module\Bridge\Metric\MetricPoint;
use App\Module\Bridge\Metric\MetricRange;
use App\Module\Bridge\Metric\MetricRow;
use App\Module\Bridge\Metric\MetricRowSource;
use App\Module\Bridge\Metric\MetricSeries;
use App\Module\Bridge\Metric\MetricShape;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricUnit;
use App\Module\Bridge\Metric\MetricWindow;
use App\Module\Bridge\Metric\TemplateMetricValues;
use App\Module\Bridge\Repository\BridgeAccountReadingRepository;
use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Bridge\Repository\WorkerRunBucketTimeRepository;
use App\Module\Bridge\Repository\WorkerRunFactRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkRequestContext;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\CountFilter;
use App\Module\Workflow\Contract\DurationEvent;
use App\Module\Workflow\Contract\MetricType;
use App\Module\Workflow\Contract\TemplateMetric;
use App\Module\Workflow\Contract\TemplateMetrics;
use App\Module\Workflow\Contract\TemplateMetricSet;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\TemplateMetricSets;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class MetricQueryHandlerTest extends KernelTestCase
{
    use BridgeScenario;

    private const string NOW = '2026-10-05 12:00:00';

    private EntityManagerInterface $em;
    private Project $project;

    /** @var list<FinishedCard> */
    private array $finished = [];

    /** @var array<string, string> */
    private array $types = [];

    /** @var array<string, list<string>> */
    private array $outcomes = [];

    /** @var array<string, list<CardMilestone>> */
    private array $milestones = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = $this->em();
        $owner = $this->user($this->em, 'metric-'.uniqid().'@example.com');
        $this->project = $this->project($this->em, $owner, 'Metrics');
    }

    public function test_a_run_query_reads_the_closed_runs_in_the_range_by_bucket(): void
    {
        $card = Uuid::v7();
        $this->fact($card, endedAt: '2026-10-01 09:00:00', cost: 1_000_000, cardNumber: 4);
        $this->fact($card, endedAt: '2026-10-01 17:00:00', cost: 3_000_000);
        $late = $this->fact($card, endedAt: '2026-10-03 08:00:00', cost: 500_000);
        $this->fact($card, endedAt: '2026-10-03 07:00:00', cost: null, startedAt: '2026-10-03 06:00:00');
        $this->fact($card, endedAt: '2026-08-01 09:00:00', cost: 9_000_000);
        $this->fact($card, endedAt: null, cost: 2_000_000, receivedAt: '2026-10-04 09:00:00');

        $view = $this->query(MetricUnit::Run, Metric::Cost, MetricStatistic::Sum);

        self::assertCount(1, $view->series);
        $series = $view->series[0];
        self::assertNull($series->group);
        self::assertEquals([
            new MetricPoint(new \DateTimeImmutable('2026-10-01 00:00:00 UTC'), 4.0, 2),
            new MetricPoint(new \DateTimeImmutable('2026-10-03 00:00:00 UTC'), 0.5, 1),
            new MetricPoint(new \DateTimeImmutable('2026-10-04 00:00:00 UTC'), 2.0, 1),
        ], $series->points);
        self::assertEquals(new MetricPoint(null, 6.5, 4), $series->total);
        self::assertCount(5, $series->rows);
        self::assertTrue($late->equals($series->rows[1]->unitId));
        self::assertSame([2.0, 0.5, null, 3.0, 1.0], array_map(static fn (MetricRow $row): int|float|null => $row->value, $series->rows));
        self::assertSame(4, $series->rows[4]->cardNumber);
    }

    public function test_a_run_query_leaves_an_open_run_out(): void
    {
        $this->fact(Uuid::v7(), endedAt: '2026-10-01 09:00:00', cost: 1_000_000);
        $this->fact(Uuid::v7(), endedAt: null, cost: 7_000_000, receivedAt: '2026-10-01 09:00:00', outcome: 'running');
        $this->fact(Uuid::v7(), endedAt: null, cost: 7_000_000, receivedAt: '2026-10-01 09:00:00', outcome: 'queued');

        $view = $this->query(MetricUnit::Run, Metric::Cost, MetricStatistic::Count);

        self::assertEquals(new MetricPoint(null, 1, 1), $view->series[0]->total);
    }

    public function test_the_count_of_rows_with_no_known_value_is_zero_and_the_sum_is_null(): void
    {
        $this->fact(Uuid::v7(), endedAt: '2026-10-01 09:00:00', cost: null);

        self::assertEquals(new MetricPoint(null, 0, 0), $this->query(MetricUnit::Run, Metric::Cost, MetricStatistic::Count)->series[0]->total);
        self::assertEquals(new MetricPoint(null, null, 0), $this->query(MetricUnit::Run, Metric::Cost, MetricStatistic::Sum)->series[0]->total);
    }

    public function test_the_stop_rate_counts_a_stop_as_one_and_leaves_out_a_run_with_no_outcome(): void
    {
        foreach (['blocked', 'failed', 'succeeded', 'gave-up', 'stopped', 'not-started'] as $outcome) {
            $this->fact(Uuid::v7(), endedAt: '2026-10-01 09:00:00', outcome: $outcome);
        }

        $view = $this->query(MetricUnit::Run, Metric::StopRate, MetricStatistic::Mean);

        self::assertEquals(new MetricPoint(null, 0.6, 5), $view->series[0]->total);
    }

    public function test_a_card_query_grouped_by_stage_gives_one_series_per_work_kind(): void
    {
        $first = $this->finishedCard(1, '2026-10-02 10:00:00');
        $second = $this->finishedCard(2, '2026-10-03 10:00:00');
        $this->finishedCard(3, '2026-08-01 10:00:00');
        $this->fact($first, endedAt: '2026-09-01 09:00:00', cost: 1_000_000, workKind: 'implement');
        $this->fact($first, endedAt: '2026-09-02 09:00:00', cost: 500_000, workKind: 'implement');
        $this->fact($first, endedAt: '2026-09-03 09:00:00', cost: 2_000_000, workKind: 'review');
        $this->fact($second, endedAt: '2026-10-03 09:00:00', cost: 4_000_000, workKind: 'implement');

        $view = $this->query(MetricUnit::Card, Metric::Cost, MetricStatistic::Sum, MetricGroup::Stage);

        self::assertSame(['implement', 'review'], array_map(static fn (MetricSeries $series): ?string => $series->group, $view->series));
        [$implement, $review] = $view->series;
        self::assertEquals(new MetricPoint(null, 5.5, 2), $implement->total);
        self::assertSame([4.0, 1.5], array_map(static fn (MetricRow $row): int|float|null => $row->value, $implement->rows));
        self::assertTrue($second->equals($implement->rows[0]->unitId));
        self::assertSame(2, $implement->rows[0]->cardNumber);
        self::assertEquals(new \DateTimeImmutable('2026-10-03 10:00:00'), $implement->rows[0]->time);
        self::assertEquals(new MetricPoint(null, 2.0, 1), $review->total);
    }

    public function test_a_card_bucket_time_adds_the_runs_with_data_and_is_unknown_with_none(): void
    {
        $known = $this->finishedCard(1, '2026-10-02 10:00:00');
        $unknown = $this->finishedCard(2, '2026-10-03 10:00:00');
        $zero = $this->finishedCard(3, '2026-10-03 11:00:00');
        $this->fact($known, endedAt: '2026-10-01 09:00:00', runId: $this->runWithBuckets(['tests' => 1500]));
        $this->fact($known, endedAt: '2026-10-02 09:00:00', runId: $this->runWithBuckets(['tests' => 2500, 'other' => 9]));
        $this->fact($known, endedAt: '2026-10-02 09:30:00');
        $this->fact($unknown, endedAt: '2026-10-03 09:00:00');
        $this->fact($zero, endedAt: '2026-10-03 09:00:00', runId: $this->runWithBuckets(['other' => 9]));

        $view = $this->query(MetricUnit::Card, new MetricShape(new MetricKey(Metric::BucketTime, 'tests')), MetricStatistic::Sum);

        self::assertSame([3 => 0, 2 => null, 1 => 4000], array_column(array_map(static fn (MetricRow $row): array => ['n' => $row->cardNumber, 'v' => $row->value], $view->series[0]->rows), 'v', 'n'));
        self::assertEquals(new MetricPoint(null, 4000, 2), $view->series[0]->total);
    }

    public function test_a_card_bucket_call_count_adds_the_runs_with_a_count_and_is_unknown_for_an_old_row(): void
    {
        $known = $this->finishedCard(1, '2026-10-02 10:00:00');
        $old = $this->finishedCard(2, '2026-10-03 10:00:00');
        $zero = $this->finishedCard(3, '2026-10-03 11:00:00');
        $this->fact($known, endedAt: '2026-10-01 09:00:00', runId: $this->runWithBuckets(['tests' => 1500], true));
        $this->fact($known, endedAt: '2026-10-02 09:00:00', runId: $this->runWithBuckets(['tests' => 2500, 'other' => 9], true));
        $this->fact($old, endedAt: '2026-10-03 09:00:00', runId: $this->runWithBuckets(['tests' => 100]));
        $this->fact($zero, endedAt: '2026-10-03 09:00:00', runId: $this->runWithBuckets(['other' => 9], true));

        $calls = $this->query(MetricUnit::Card, new MetricShape(new MetricKey(Metric::BucketCalls, 'tests')), MetricStatistic::Sum);
        $withSubagents = $this->query(MetricUnit::Card, new MetricShape(new MetricKey(Metric::BucketTimeAll, 'tests')), MetricStatistic::Sum);

        $byCard = static fn (MetricQueryView $view): array => array_column(array_map(static fn (MetricRow $row): array => ['n' => $row->cardNumber, 'v' => $row->value], $view->series[0]->rows), 'v', 'n');
        self::assertSame([3 => 0, 2 => null, 1 => 6], $byCard($calls));
        self::assertSame([3 => 0, 2 => null, 1 => 8000], $byCard($withSubagents));
    }

    public function test_a_card_harness_event_count_adds_the_runs_with_a_map_and_is_unknown_with_none(): void
    {
        $known = $this->finishedCard(1, '2026-10-02 10:00:00');
        $unknown = $this->finishedCard(2, '2026-10-03 10:00:00');
        $zero = $this->finishedCard(3, '2026-10-03 11:00:00');
        $this->fact($known, endedAt: '2026-10-01 09:00:00', runId: $this->runWithEvents(['diagnostics' => 3]));
        $this->fact($known, endedAt: '2026-10-02 09:00:00', runId: $this->runWithEvents(['diagnostics' => 4, 'other' => 9]));
        $this->fact($known, endedAt: '2026-10-02 09:30:00', runId: $this->runWithEvents(null));
        $this->fact($unknown, endedAt: '2026-10-03 09:00:00', runId: $this->runWithEvents(null));
        $this->fact($zero, endedAt: '2026-10-03 09:00:00', runId: $this->runWithEvents([]));

        $view = $this->query(MetricUnit::Card, new MetricShape(new MetricKey(Metric::HarnessEvents, 'diagnostics')), MetricStatistic::Sum);

        self::assertSame([3 => 0, 2 => null, 1 => 7], array_column(array_map(static fn (MetricRow $row): array => ['n' => $row->cardNumber, 'v' => $row->value], $view->series[0]->rows), 'v', 'n'));
        self::assertEquals(new MetricPoint(null, 7, 2), $view->series[0]->total);
    }

    /**
     * @param array<string, int>|null $events
     *
     * @return Uuid the id of a stored run that holds the harness events
     */
    private function runWithEvents(?array $events): Uuid
    {
        $run = $this->seedRun($this->em, $this->project);
        $run->harnessEvents = $events;
        $this->em->flush();
        $this->em->getConnection()->executeStatement('DELETE FROM bridge_worker_run_facts WHERE run_id = :run', ['run' => (string) $run->id]);

        return $run->id ?? throw new \LogicException('A stored run has an id.');
    }

    /**
     * @param array<string, int> $times
     *
     * @return Uuid the id of a stored run that has the bucket rows
     */
    private function runWithBuckets(array $times, bool $withFigures = false): Uuid
    {
        $run = $this->seedRun($this->em, $this->project);
        // The fact listener wrote a fact row for the run, and the test writes its own.
        $this->em->getConnection()->executeStatement('DELETE FROM bridge_worker_run_facts WHERE run_id = :run', ['run' => (string) $run->id]);
        foreach ($times as $bucket => $ms) {
            $this->em->getConnection()->insert('bridge_worker_run_bucket_times', ['id' => (string) Uuid::v7(), 'run_id' => (string) $run->id, 'bucket' => (string) $bucket, 'ms' => $ms] + ($withFigures ? ['ms_with_subagents' => $ms * 2, 'calls' => 3] : []));
        }

        return $run->id ?? throw new \LogicException('A stored run has an id.');
    }

    public function test_a_card_with_a_started_run_and_no_cost_has_an_unknown_cost(): void
    {
        $priced = $this->finishedCard(1, '2026-10-02 10:00:00');
        $partial = $this->finishedCard(2, '2026-10-03 10:00:00');
        $this->fact($priced, endedAt: '2026-10-01 09:00:00', cost: 1_000_000);
        $this->fact($priced, endedAt: null, cost: null, startedAt: null, receivedAt: '2026-10-01 09:00:00', outcome: 'stopped');
        $this->fact($partial, endedAt: '2026-10-01 09:00:00', cost: 1_000_000);
        $this->fact($partial, endedAt: '2026-10-01 10:00:00', cost: null, startedAt: '2026-10-01 09:30:00');

        $view = $this->query(MetricUnit::Card, Metric::Cost, MetricStatistic::Sum);

        self::assertSame([null, 1.0], array_map(static fn (MetricRow $row): int|float|null => $row->value, $view->series[0]->rows));
        self::assertEquals(new MetricPoint(null, 1.0, 1), $view->series[0]->total);
    }

    public function test_a_command_run_leaves_the_cost_of_its_card_known(): void
    {
        $card = $this->finishedCard(1, '2026-10-02 10:00:00');
        $this->fact($card, endedAt: '2026-10-01 09:00:00', cost: 1_000_000);
        $this->fact($card, endedAt: '2026-10-01 10:00:00', cost: null, startedAt: '2026-10-01 09:30:00', kind: 'command');

        $view = $this->query(MetricUnit::Card, Metric::Cost, MetricStatistic::Sum);

        self::assertSame([1.0], array_map(static fn (MetricRow $row): int|float|null => $row->value, $view->series[0]->rows));
    }

    public function test_a_card_query_grouped_by_card_type_reads_the_types_from_the_board(): void
    {
        $bug = $this->finishedCard(1, '2026-10-02 10:00:00');
        $feature = $this->finishedCard(2, '2026-10-03 10:00:00');
        $this->types = [(string) $bug => 'bug', (string) $feature => 'feature'];
        foreach ([$bug, $bug, $bug, $feature] as $card) {
            $this->fact($card, endedAt: '2026-10-01 09:00:00');
        }

        $view = $this->query(MetricUnit::Card, Metric::Runs, MetricStatistic::Sum, MetricGroup::CardType);

        self::assertSame(['bug', 'feature'], array_map(static fn (MetricSeries $series): ?string => $series->group, $view->series));
        self::assertEquals(new MetricPoint(null, 3, 1), $view->series[0]->total);
        self::assertEquals(new MetricPoint(null, 1, 1), $view->series[1]->total);
    }

    public function test_a_run_of_another_subject_counts_only_where_no_card_is_needed(): void
    {
        $card = $this->finishedCard(1, '2026-10-02 10:00:00');
        $this->fact($card, endedAt: '2026-10-01 09:00:00', cost: 1_000_000, workKind: 'implement');
        $this->fact($card, endedAt: '2026-10-01 10:00:00', cost: 7_000_000, workKind: 'implement', subjectType: 'analysis');

        self::assertEquals(new MetricPoint(null, 8.0, 2), $this->query(MetricUnit::Run, Metric::Cost, MetricStatistic::Sum)->series[0]->total);
        $byStage = $this->query(MetricUnit::Run, Metric::Cost, MetricStatistic::Sum, MetricGroup::Stage);
        self::assertCount(1, $byStage->series);
        self::assertEquals(new MetricPoint(null, 1.0, 1), $byStage->series[0]->total);
        self::assertEquals(new MetricPoint(null, 1.0, 1), $this->query(MetricUnit::Card, Metric::Cost, MetricStatistic::Sum)->series[0]->total);
    }

    public function test_it_groups_the_runs_by_harness_and_by_account(): void
    {
        $card = $this->finishedCard(1, '2026-10-02 10:00:00');
        $this->fact($card, endedAt: '2026-10-01 09:00:00', cost: 1_000_000, harness: 'codex', account: 'work');
        $this->fact($card, endedAt: '2026-10-01 10:00:00', cost: 2_000_000, harness: 'codex', account: 'personal');
        $this->fact($card, endedAt: '2026-10-01 11:00:00', cost: 4_000_000, harness: 'claude-code', account: 'work');
        $this->fact($card, endedAt: '2026-10-01 12:00:00', cost: 8_000_000);

        $totals = static fn (MetricQueryView $view): array => array_map(static fn (MetricSeries $series): array => [$series->group, $series->total->value], $view->series);

        self::assertEqualsCanonicalizing(
            [['claude-code', 4.0], ['codex', 3.0], [null, 8.0]],
            $totals($this->query(MetricUnit::Run, Metric::Cost, MetricStatistic::Sum, MetricGroup::Harness)),
        );
        self::assertEqualsCanonicalizing(
            [['personal', 2.0], ['work', 5.0], [null, 8.0]],
            $totals($this->query(MetricUnit::Card, Metric::Cost, MetricStatistic::Sum, MetricGroup::Account)),
        );
    }

    public function test_it_groups_the_runs_by_the_three_versions(): void
    {
        $card = Uuid::v7();
        $this->fact($card, endedAt: '2026-10-01 09:00:00', cost: 1_000_000, harnessVersion: '2.1.300', cliVersion: '0.9.0', skillVersion: '5.4.0');
        $this->fact($card, endedAt: '2026-10-01 10:00:00', cost: 2_000_000, harnessVersion: '2.1.301', cliVersion: '0.9.0', skillVersion: '5.5.0');
        $this->fact($card, endedAt: '2026-10-01 11:00:00', cost: 4_000_000, harnessVersion: '2.1.301', cliVersion: '0.9.0', skillVersion: '5.5.0');
        $this->fact($card, endedAt: '2026-10-01 12:00:00', cost: 8_000_000);

        $totals = static fn (MetricQueryView $view): array => array_map(static fn (MetricSeries $series): array => [$series->group, $series->total->value], $view->series);

        self::assertEqualsCanonicalizing(
            [['2.1.300', 1.0], ['2.1.301', 6.0], [null, 8.0]],
            $totals($this->query(MetricUnit::Run, Metric::Cost, MetricStatistic::Sum, MetricGroup::HarnessVersion)),
        );
        self::assertEqualsCanonicalizing(
            [['0.9.0', 7.0], [null, 8.0]],
            $totals($this->query(MetricUnit::Run, Metric::Cost, MetricStatistic::Sum, MetricGroup::CliVersion)),
        );
        self::assertEqualsCanonicalizing(
            [['5.4.0', 1.0], ['5.5.0', 6.0], [null, 8.0]],
            $totals($this->query(MetricUnit::Run, Metric::Cost, MetricStatistic::Sum, MetricGroup::SkillVersion)),
        );
    }

    public function test_a_template_rate_of_any_fix_request_counts_a_card_with_none_as_false(): void
    {
        $cards = [];
        foreach ([1, 3, 0, 0] as $i => $fixes) {
            $cards[$i] = $this->finishedCard($i + 1, '2026-10-0'.($i + 1).' 10:00:00');
            for ($n = 0; $n < $fixes; ++$n) {
                $this->request($cards[$i], 'fix', 'fix', 'checks-failed');
            }
            $this->request($cards[$i], 'implement', null, null);
        }
        $this->request(Uuid::v7(), 'fix', 'fix', 'conflict');
        $needed = new TemplateMetric('needed-fix', 'Needed a fix', 'Cards that needed a fix.', MetricType::Rate, filter: new CountFilter(role: 'fix'));

        $view = $this->query(MetricUnit::Card, MetricShape::template($needed), MetricStatistic::Mean);

        $series = $view->series[0];
        self::assertEquals(new MetricPoint(null, 0.5, 4), $series->total);
        self::assertSame([0, 0, 1, 1], array_map(static fn (MetricRow $row): int|float|null => $row->value, $series->rows));
    }

    public function test_a_template_count_sums_the_requests_that_the_filter_picks(): void
    {
        $first = $this->finishedCard(1, '2026-10-02 10:00:00');
        $second = $this->finishedCard(2, '2026-10-03 10:00:00');
        $third = $this->finishedCard(3, '2026-10-04 10:00:00');
        $this->request($first, 'fix', 'fix', 'conflict');
        $this->request($first, 'fix', 'fix', 'checks-failed');
        $this->request($first, 'review', 'code-review', 'checks-failed');
        $this->request($second, 'fix', 'fix', 'conflict');
        $conflicts = new TemplateMetric('conflicts', 'Conflicts', 'Fix requests for a conflict.', MetricType::Count, filter: new CountFilter(role: 'fix', reason: 'conflict'), splitBy: 'reason');

        $view = $this->query(MetricUnit::Card, MetricShape::template($conflicts), MetricStatistic::Sum);

        self::assertEquals(new MetricPoint(null, 2, 3), $view->series[0]->total);
        $values = [];
        foreach ($view->series[0]->rows as $row) {
            $values[(string) $row->unitId] = $row->value;
        }
        $expected = [(string) $first => 1, (string) $second => 1, (string) $third => 0];
        ksort($expected);
        ksort($values);
        self::assertSame($expected, $values);
    }

    public function test_a_template_rate_of_an_outcome_is_true_when_any_move_set_it(): void
    {
        $merged = $this->finishedCard(1, '2026-10-02 10:00:00');
        $other = $this->finishedCard(2, '2026-10-03 10:00:00');
        $this->finishedCard(3, '2026-10-04 10:00:00');
        $this->outcomes = [(string) $merged => ['abandoned', 'merged'], (string) $other => ['abandoned']];
        $rate = new TemplateMetric('merge-rate', 'Merge rate', 'Cards that merged.', MetricType::Rate, outcome: 'merged');

        $view = $this->query(MetricUnit::Card, MetricShape::template($rate), MetricStatistic::Mean);

        self::assertEquals(new MetricPoint(null, 1 / 3, 3), $view->series[0]->total);
    }

    public function test_a_template_duration_is_in_hours_and_leaves_a_card_with_no_start_or_end_out(): void
    {
        $merged = $this->finishedCard(1, '2026-10-02 10:00:00');
        $open = $this->finishedCard(2, '2026-10-03 10:00:00');
        $none = $this->finishedCard(3, '2026-10-04 10:00:00');
        $this->milestones = [
            (string) $merged => [
                new CardMilestone(DurationEvent::PR_OPENED, new \DateTimeImmutable('2026-10-01 08:00:00')),
                new CardMilestone(DurationEvent::PR_MERGED, new \DateTimeImmutable('2026-10-01 11:30:00')),
            ],
            (string) $open => [new CardMilestone(DurationEvent::PR_OPENED, new \DateTimeImmutable('2026-10-01 08:00:00'))],
        ];
        $hours = new TemplateMetric('hours-to-merge', 'Hours to merge', 'Hours.', MetricType::Duration, from: new DurationEvent(DurationEvent::PR_OPENED), to: new DurationEvent(DurationEvent::PR_MERGED));

        $view = $this->query(MetricUnit::Card, MetricShape::template($hours), MetricStatistic::Mean);

        self::assertEquals(new MetricPoint(null, 3.5, 1), $view->series[0]->total);
        $values = [];
        foreach ($view->series[0]->rows as $row) {
            $values[(string) $row->unitId] = $row->value;
        }
        $expected = [(string) $merged => 3.5, (string) $open => null, (string) $none => null];
        ksort($expected);
        ksort($values);
        self::assertSame($expected, $values);
    }

    public function test_a_template_metric_groups_by_card_type_and_gives_each_variant_of_its_runs_the_whole_value(): void
    {
        $bug = $this->finishedCard(1, '2026-10-02 10:00:00');
        $feature = $this->finishedCard(2, '2026-10-03 10:00:00');
        $mixed = $this->finishedCard(3, '2026-10-04 10:00:00');
        $unrun = $this->finishedCard(4, '2026-10-05 10:00:00');
        $this->types = [(string) $bug => 'bug', (string) $feature => 'feature', (string) $mixed => 'feature', (string) $unrun => 'feature'];
        $this->fact($bug, endedAt: '2026-10-01 09:00:00', variant: 'a');
        $this->fact($bug, endedAt: '2026-10-01 10:00:00', variant: 'a');
        $this->fact($feature, endedAt: '2026-10-01 09:00:00', variant: 'b');
        $this->fact($mixed, endedAt: '2026-10-01 09:00:00', variant: 'a');
        $this->fact($mixed, endedAt: '2026-10-01 10:00:00', variant: 'b');
        $this->request($bug, 'fix', 'fix', null);
        $this->request($mixed, 'fix', 'fix', null);
        $this->request($mixed, 'fix', 'fix', null);
        $count = new TemplateMetric('fix-rounds', 'Fix rounds', 'Fixes.', MetricType::Count, filter: new CountFilter(role: 'fix'));

        $byType = $this->query(MetricUnit::Card, MetricShape::template($count), MetricStatistic::Sum, MetricGroup::CardType);
        $byVariant = $this->query(MetricUnit::Card, MetricShape::template($count), MetricStatistic::Sum, MetricGroup::Variant);

        self::assertSame(['bug', 'feature'], array_map(static fn (MetricSeries $series): ?string => $series->group, $byType->series));
        self::assertSame(['a', 'b', null], array_map(static fn (MetricSeries $series): ?string => $series->group, $byVariant->series));
        self::assertEquals(new MetricPoint(null, 3, 2), $byVariant->series[0]->total);
        self::assertEquals(new MetricPoint(null, 2, 2), $byVariant->series[1]->total);
        self::assertEquals(new MetricPoint(null, 0, 1), $byVariant->series[2]->total);
    }

    public function test_the_account_usage_reads_the_readings_of_the_named_bridges_that_follow_the_project(): void
    {
        $owner = $this->project->owner;
        $follows = [(string) $this->project->id];
        $laptop = $this->seedBridge($this->em, $owner, projects: $follows);
        $laptop->name = 'laptop';
        $nameless = $this->seedBridge($this->em, $owner, projects: $follows);
        $desk = $this->seedBridge($this->em, $owner, projects: []);
        $desk->name = 'desk';
        $stranger = $this->seedBridge($this->em, $this->user($this->em, 'metric-stranger-'.uniqid().'@example.com'), projects: $follows);
        $stranger->name = 'stranger';
        $this->em->flush();
        $this->seedAccountReading($this->em, $laptop, '2026-10-01 10:00:00', usedPercent: 40.0);
        $this->seedAccountReading($this->em, $laptop, '2026-10-01 18:00:00', usedPercent: 70.0);
        $this->seedAccountReading($this->em, $laptop, '2026-10-03 09:00:00', usedPercent: 20.0);
        $this->seedAccountReading($this->em, $laptop, '2026-10-02 09:00:00', usedPercent: null);
        $this->seedAccountReading($this->em, $laptop, '2026-08-01 09:00:00', usedPercent: 99.0);
        $this->seedAccountReading($this->em, $laptop, '2026-10-01 10:00:00', window: 'week', usedPercent: 30.0);
        $this->seedAccountReading($this->em, $nameless, '2026-10-01 10:00:00', usedPercent: 95.0);
        $this->seedAccountReading($this->em, $desk, '2026-10-01 10:00:00', usedPercent: 96.0);
        $this->seedAccountReading($this->em, $stranger, '2026-10-01 10:00:00', usedPercent: 97.0);

        $view = $this->query(MetricUnit::Reading, Metric::AccountUsage, MetricStatistic::Max, MetricGroup::AccountWindow);

        self::assertSame(['laptop/work session', 'laptop/work week'], array_map(static fn (MetricSeries $series): ?string => $series->group, $view->series));
        [$session, $week] = $view->series;
        self::assertEquals([
            new MetricPoint(new \DateTimeImmutable('2026-10-01 00:00:00 UTC'), 70.0, 2),
            new MetricPoint(new \DateTimeImmutable('2026-10-03 00:00:00 UTC'), 20.0, 1),
        ], $session->points);
        self::assertEquals(new MetricPoint(null, 70.0, 3), $session->total);
        self::assertSame([20.0, 70.0, 40.0], array_map(static fn (MetricRow $row): int|float|null => $row->value, $session->rows));
        self::assertNull($session->rows[0]->cardNumber);
        self::assertEquals(new MetricPoint(null, 30.0, 1), $week->total);
    }

    public function test_the_account_usage_has_no_rows_for_a_project_no_bridge_follows(): void
    {
        $bridge = $this->seedBridge($this->em, $this->project->owner, projects: []);
        $bridge->name = 'idle';
        $this->em->flush();
        $this->seedAccountReading($this->em, $bridge, '2026-10-01 10:00:00', usedPercent: 40.0);

        $view = $this->query(MetricUnit::Reading, Metric::AccountUsage, MetricStatistic::Max, MetricGroup::AccountWindow);

        self::assertSame([], $view->series);
    }

    public function test_the_session_and_week_usage_read_the_change_of_the_run_fact(): void
    {
        $card = Uuid::v7();
        $this->fact($card, endedAt: '2026-10-01 09:00:00', sessionUsageDelta: 5.5, weekUsageDelta: 1.0);
        $this->fact($card, endedAt: '2026-10-01 17:00:00', sessionUsageDelta: 2.5, weekUsageDelta: null);
        $this->fact($card, endedAt: '2026-10-02 08:00:00');

        $session = $this->query(MetricUnit::Run, Metric::SessionUsage, MetricStatistic::Mean);
        $max = $this->query(MetricUnit::Run, Metric::SessionUsage, MetricStatistic::Max);
        $week = $this->query(MetricUnit::Run, Metric::WeekUsage, MetricStatistic::Max);

        self::assertEquals(new MetricPoint(null, 4.0, 2), $session->series[0]->total);
        self::assertEquals(new MetricPoint(null, 5.5, 2), $max->series[0]->total);
        self::assertSame([null, 2.5, 5.5], array_map(static fn (MetricRow $row): int|float|null => $row->value, $session->series[0]->rows));
        self::assertEquals(new MetricPoint(null, 1.0, 1), $week->series[0]->total);
        self::assertSame(3, \count($week->series[0]->rows));
    }

    private function request(Uuid $card, string $kind, ?string $role, ?string $reason): void
    {
        $request = $this->seedWorkRequest($this->em, $this->project, $card, $kind, state: WorkRequestState::Done, subject: WorkSubject::card($card));
        $request->role = $role;
        $request->context = new WorkRequestContext(reason: $reason);
        $this->em->flush();
    }

    /** @return iterable<string, array{MetricUnit, MetricShape, MetricStatistic, MetricGroup, string}> */
    public static function refusals(): iterable
    {
        $built = static fn (Metric $metric): MetricShape => new MetricShape(new MetricKey($metric));
        $rate = MetricShape::template(TemplateMetricSets::rate());
        $count = MetricShape::template(TemplateMetricSets::count());
        $duration = MetricShape::template(TemplateMetricSets::duration());

        yield 'a card metric on the run unit' => [MetricUnit::Run, $built(Metric::Runs), MetricStatistic::Sum, MetricGroup::None, 'unit'];
        yield 'the stop rate on the card unit' => [MetricUnit::Card, $built(Metric::StopRate), MetricStatistic::Mean, MetricGroup::None, 'unit'];
        yield 'a percentile of a ratio' => [MetricUnit::Run, $built(Metric::StopRate), MetricStatistic::P90, MetricGroup::None, 'statistic'];
        yield 'a template metric on the run unit' => [MetricUnit::Run, $rate, MetricStatistic::Mean, MetricGroup::None, 'unit'];
        yield 'a sum of a template rate' => [MetricUnit::Card, $rate, MetricStatistic::Sum, MetricGroup::None, 'statistic'];
        yield 'a template rate by stage' => [MetricUnit::Card, $rate, MetricStatistic::Mean, MetricGroup::Stage, 'group'];
        yield 'a template count by model' => [MetricUnit::Card, $count, MetricStatistic::Sum, MetricGroup::Model, 'group'];
        yield 'a template duration by bridge' => [MetricUnit::Card, $duration, MetricStatistic::Median, MetricGroup::Bridge, 'group'];
        yield 'a template rate by harness' => [MetricUnit::Card, $rate, MetricStatistic::Mean, MetricGroup::Harness, 'group'];
        yield 'a template rate by skill version' => [MetricUnit::Card, $rate, MetricStatistic::Mean, MetricGroup::SkillVersion, 'group'];
        yield 'a template count by account' => [MetricUnit::Card, $count, MetricStatistic::Sum, MetricGroup::Account, 'group'];
        yield 'the account usage on the run unit' => [MetricUnit::Run, $built(Metric::AccountUsage), MetricStatistic::Max, MetricGroup::AccountWindow, 'unit'];
        yield 'a sum of the account usage' => [MetricUnit::Reading, $built(Metric::AccountUsage), MetricStatistic::Sum, MetricGroup::AccountWindow, 'statistic'];
        yield 'the account usage with no grouping' => [MetricUnit::Reading, $built(Metric::AccountUsage), MetricStatistic::Max, MetricGroup::None, 'group'];
        yield 'the account usage by stage' => [MetricUnit::Reading, $built(Metric::AccountUsage), MetricStatistic::Max, MetricGroup::Stage, 'group'];
        yield 'a session usage on the card unit' => [MetricUnit::Card, $built(Metric::SessionUsage), MetricStatistic::Max, MetricGroup::None, 'unit'];
        yield 'a week usage on the reading unit' => [MetricUnit::Reading, $built(Metric::WeekUsage), MetricStatistic::Max, MetricGroup::None, 'unit'];
        yield 'a sum of a session usage' => [MetricUnit::Run, $built(Metric::SessionUsage), MetricStatistic::Sum, MetricGroup::None, 'statistic'];
        yield 'a session usage by account window' => [MetricUnit::Run, $built(Metric::SessionUsage), MetricStatistic::Max, MetricGroup::AccountWindow, 'group'];
        yield 'the max of a cost' => [MetricUnit::Run, $built(Metric::Cost), MetricStatistic::Max, MetricGroup::None, 'statistic'];
        yield 'a cost on the reading unit' => [MetricUnit::Reading, $built(Metric::Cost), MetricStatistic::Mean, MetricGroup::None, 'unit'];
    }

    #[DataProvider('refusals')]
    public function test_it_refuses_a_combination_the_metric_does_not_allow(MetricUnit $unit, MetricShape $metric, MetricStatistic $statistic, MetricGroup $group, string $field): void
    {
        try {
            $this->query($unit, $metric, $statistic, $group);
            self::fail('The query was not refused.');
        } catch (DomainErrors $errors) {
            self::assertSame([$field], array_keys($errors->errors));
        }
    }

    public function test_a_compare_moves_the_previous_rows_onto_the_dates_of_the_current_ones(): void
    {
        // The window of 30 days ends at NOW, so the previous window runs from 2026-08-06 12:00 to 2026-09-05 12:00.
        $this->fact(Uuid::v7(), endedAt: '2026-09-10 09:00:00', cost: 2_000_000, cardNumber: 1);
        $this->fact(Uuid::v7(), endedAt: '2026-08-11 09:00:00', cost: 1_000_000, cardNumber: 2);
        $this->fact(Uuid::v7(), endedAt: '2026-08-01 09:00:00', cost: 9_000_000, cardNumber: 3);

        $view = $this->query(MetricUnit::Run, Metric::Cost, MetricStatistic::Sum, compare: MetricCompare::Previous);

        $series = $view->series[0];
        self::assertEquals([new MetricPoint(new \DateTimeImmutable('2026-09-10 00:00:00 UTC'), 2.0, 1)], $series->points);
        // 2026-08-11 plus 30 days is 2026-09-10, the same day as the current row.
        self::assertEquals([new MetricPoint(new \DateTimeImmutable('2026-09-10 00:00:00 UTC'), 1.0, 1)], $series->previousPoints);
        self::assertNotNull($series->comparison);
        self::assertEquals(new MetricPoint(null, 1.0, 1), $series->comparison->previous);
        self::assertSame(1.0, $series->comparison->change);
        self::assertSame(MetricChangeKind::Relative, $series->comparison->changeKind);
        self::assertNull($series->comparison->sign);
        self::assertEquals(new MetricWindow(new \DateTimeImmutable('2026-09-05 12:00:00'), new \DateTimeImmutable(self::NOW)), $view->current);
        self::assertEquals(new MetricWindow(new \DateTimeImmutable('2026-08-06 12:00:00'), new \DateTimeImmutable('2026-09-05 12:00:00')), $view->previous);
    }

    public function test_a_compare_leaves_out_a_run_after_the_clock_reading(): void
    {
        $this->fact(Uuid::v7(), endedAt: '2026-09-10 09:00:00', cost: 2_000_000);
        $this->fact(Uuid::v7(), endedAt: '2026-10-05 13:00:00', cost: 9_000_000);

        $view = $this->query(MetricUnit::Run, Metric::Cost, MetricStatistic::Sum, compare: MetricCompare::Previous);

        self::assertEquals(new MetricPoint(null, 2.0, 1), $view->series[0]->total);
    }

    public function test_no_compare_leaves_the_comparison_out(): void
    {
        $this->fact(Uuid::v7(), endedAt: '2026-09-10 09:00:00', cost: 2_000_000);

        $view = $this->query(MetricUnit::Run, Metric::Cost, MetricStatistic::Sum);

        self::assertNull($view->series[0]->comparison);
        self::assertSame([], $view->series[0]->previousPoints);
        self::assertNull($view->previous);
    }

    public function test_a_compare_keeps_a_line_that_only_the_previous_period_holds(): void
    {
        $this->fact(Uuid::v7(), endedAt: '2026-09-10 09:00:00', cost: 2_000_000, workKind: 'implement');
        $this->fact(Uuid::v7(), endedAt: '2026-08-11 09:00:00', cost: 1_000_000, workKind: 'review');

        $view = $this->query(MetricUnit::Run, Metric::Cost, MetricStatistic::Sum, MetricGroup::Stage, MetricCompare::Previous);

        self::assertSame(['implement', 'review'], array_map(static fn (MetricSeries $series): ?string => $series->group, $view->series));
        self::assertSame([], $view->series[1]->points);
        self::assertSame(1.0, $view->series[1]->comparison?->previous->value);
        self::assertNull($view->series[1]->comparison->change);
    }

    public function test_a_compare_of_all_time_is_refused(): void
    {
        try {
            $this->query(MetricUnit::Run, Metric::Cost, MetricStatistic::Sum, compare: MetricCompare::Previous, range: MetricRange::All);
            self::fail('The query was not refused.');
        } catch (DomainErrors $errors) {
            self::assertSame(['compare' => MetricQueryHandler::COMPARE_NOT_ALLOWED], $errors->errors);
        }
    }

    public function test_seven_days_take_the_bucket_day_alone(): void
    {
        $this->fact(Uuid::v7(), endedAt: '2026-10-03 09:00:00', cost: 1_000_000);

        self::assertCount(1, $this->query(MetricUnit::Run, Metric::Cost, MetricStatistic::Sum, range: MetricRange::SevenDays)->series);
        try {
            $this->query(MetricUnit::Run, Metric::Cost, MetricStatistic::Sum, range: MetricRange::SevenDays, bucket: MetricBucket::Week);
            self::fail('The query was not refused.');
        } catch (DomainErrors $errors) {
            self::assertSame(['bucket' => MetricQueryHandler::BUCKET_NOT_ALLOWED], $errors->errors);
        }
    }

    public function test_the_seven_day_window_leaves_out_an_older_run(): void
    {
        $this->fact(Uuid::v7(), endedAt: '2026-10-03 09:00:00', cost: 1_000_000);
        $this->fact(Uuid::v7(), endedAt: '2026-09-27 09:00:00', cost: 4_000_000);

        $view = $this->query(MetricUnit::Run, Metric::Cost, MetricStatistic::Sum, compare: MetricCompare::Previous, range: MetricRange::SevenDays);

        self::assertEquals(new MetricPoint(null, 1.0, 1), $view->series[0]->total);
        self::assertEquals(new MetricPoint(null, 4.0, 1), $view->series[0]->comparison?->previous);
    }

    public function test_a_bucket_time_each_query_gives_one_line_per_bucket_with_zero_for_an_untouched_one(): void
    {
        $this->fact(Uuid::v7(), endedAt: '2026-10-01 09:00:00', cardNumber: 1, runId: $this->runWithBuckets(['tests' => 1500, 'edits' => 500]));
        $this->fact(Uuid::v7(), endedAt: '2026-10-02 09:00:00', cardNumber: 2, runId: $this->runWithBuckets(['tests' => 2500]));
        $this->fact(Uuid::v7(), endedAt: '2026-10-02 10:00:00', cardNumber: 3);

        $view = $this->query(MetricUnit::Run, Metric::BucketTimeEach, MetricStatistic::Mean);

        self::assertSame(['edits', 'tests'], array_map(static fn (MetricSeries $series): ?string => $series->group, $view->series));
        self::assertEquals(new MetricPoint(null, 250.0, 2), $view->series[0]->total);
        self::assertEquals(new MetricPoint(null, 2000.0, 2), $view->series[1]->total);
        // The means of the lines add up to the mean of the whole: (1500 + 500 + 2500 + 0) / 2 = 2250.
        self::assertEquals(2250, $view->series[0]->total->value + $view->series[1]->total->value);
    }

    public function test_a_bucket_time_each_query_by_card_adds_the_runs_of_a_card_per_bucket(): void
    {
        $card = $this->finishedCard(1, '2026-10-02 10:00:00');
        $this->fact($card, endedAt: '2026-10-01 09:00:00', runId: $this->runWithBuckets(['tests' => 1500], true));
        $this->fact($card, endedAt: '2026-10-02 09:00:00', runId: $this->runWithBuckets(['edits' => 500], true));
        $this->fact($card, endedAt: '2026-10-02 09:30:00');

        $time = $this->query(MetricUnit::Card, Metric::BucketTimeEach, MetricStatistic::Sum);
        $withSubagents = $this->query(MetricUnit::Card, Metric::BucketTimeAllEach, MetricStatistic::Sum);
        $calls = $this->query(MetricUnit::Card, Metric::BucketCallsEach, MetricStatistic::Sum);

        self::assertSame([500, 1500], array_map(static fn (MetricSeries $series): int|float|null => $series->total->value, $time->series));
        self::assertSame([1000, 3000], array_map(static fn (MetricSeries $series): int|float|null => $series->total->value, $withSubagents->series));
        self::assertSame([3, 3], array_map(static fn (MetricSeries $series): int|float|null => $series->total->value, $calls->series));
    }

    public function test_a_bucket_time_each_compare_fills_a_bucket_of_one_period_with_zero_in_the_other(): void
    {
        $this->fact(Uuid::v7(), endedAt: '2026-09-10 09:00:00', cardNumber: 1, runId: $this->runWithBuckets(['tests' => 1500]));
        $this->fact(Uuid::v7(), endedAt: '2026-08-11 09:00:00', cardNumber: 2, runId: $this->runWithBuckets(['edits' => 500]));

        $view = $this->query(MetricUnit::Run, Metric::BucketTimeEach, MetricStatistic::Mean, compare: MetricCompare::Previous);

        self::assertSame(['edits', 'tests'], array_map(static fn (MetricSeries $series): ?string => $series->group, $view->series));
        [$edits, $tests] = $view->series;
        self::assertEquals(new MetricPoint(null, 0.0, 1), $edits->total);
        self::assertEquals(new MetricPoint(null, 500.0, 1), $edits->comparison?->previous);
        self::assertNotNull($tests->comparison);
        self::assertEquals(new MetricPoint(null, 0.0, 1), $tests->comparison->previous);
        self::assertSame(1500.0, $tests->comparison->change);
        self::assertSame(MetricChangeKind::Absolute, $tests->comparison->changeKind);
    }

    public function test_a_bucket_time_each_compare_by_card_fills_a_bucket_of_one_period_with_zero_in_the_other(): void
    {
        $this->fact($this->finishedCard(1, '2026-09-10 10:00:00'), endedAt: '2026-09-10 09:00:00', runId: $this->runWithBuckets(['tests' => 1500], true));
        $this->fact($this->finishedCard(2, '2026-08-11 10:00:00'), endedAt: '2026-08-11 09:00:00', runId: $this->runWithBuckets(['edits' => 500], true));

        $view = $this->query(MetricUnit::Card, Metric::BucketCallsEach, MetricStatistic::Sum, compare: MetricCompare::Previous);

        self::assertSame(['edits', 'tests'], array_map(static fn (MetricSeries $series): ?string => $series->group, $view->series));
        self::assertEquals(new MetricPoint(null, 0, 1), $view->series[0]->total);
        self::assertEquals(new MetricPoint(null, 0, 1), $view->series[1]->comparison?->previous);
    }

    public function test_a_bucket_time_each_query_leaves_a_run_with_no_bucket_data_out(): void
    {
        $this->fact(Uuid::v7(), endedAt: '2026-10-01 09:00:00');

        self::assertSame([], $this->query(MetricUnit::Run, Metric::BucketTimeEach, MetricStatistic::Mean)->series);
    }

    private function query(MetricUnit $unit, Metric|MetricShape $metric, MetricStatistic $statistic, MetricGroup $group = MetricGroup::None, MetricCompare $compare = MetricCompare::Off, MetricRange $range = MetricRange::ThirtyDays, MetricBucket $bucket = MetricBucket::Day): MetricQueryView
    {
        $shape = $metric instanceof MetricShape ? $metric : new MetricShape(new MetricKey($metric));
        $finished = new readonly class($this->finished) implements FinishedCardSourceInterface {
            /** @param list<FinishedCard> $cards */
            public function __construct(
                private array $cards,
            ) {
            }

            #[\Override]
            public function finishedCards(Project $project, ?\DateTimeImmutable $completedSince, ?\DateTimeImmutable $completedBefore = null): array
            {
                return array_values(array_filter($this->cards, static fn (FinishedCard $card): bool => (null === $completedSince || $card->completedAt >= $completedSince) && (null === $completedBefore || $card->completedAt < $completedBefore)));
            }
        };
        $reports = new readonly class($this->types, $this->outcomes) implements CardReportSourceInterface {
            /**
             * @param array<string, string>       $types
             * @param array<string, list<string>> $outcomes
             */
            public function __construct(
                private array $types,
                private array $outcomes,
            ) {
            }

            #[\Override]
            public function columnsFor(Project $project, array $cardIds): array
            {
                return [];
            }

            #[\Override]
            public function outcomesOfCards(Project $project, array $cardIds): array
            {
                return $this->outcomes;
            }

            #[\Override]
            public function typesFor(Project $project, array $cardIds): array
            {
                return $this->types;
            }

            #[\Override]
            public function historyStartFor(Project $project): ?\DateTimeImmutable
            {
                return null;
            }
        };
        $facts = self::getContainer()->get(WorkerRunFactRepository::class);
        self::assertInstanceOf(WorkerRunFactRepository::class, $facts);
        $bucketTimes = self::getContainer()->get(WorkerRunBucketTimeRepository::class);
        self::assertInstanceOf(WorkerRunBucketTimeRepository::class, $bucketTimes);
        $workerRuns = self::getContainer()->get(WorkerRunRepository::class);
        self::assertInstanceOf(WorkerRunRepository::class, $workerRuns);
        $milestones = new readonly class($this->milestones) implements CardMilestoneSourceInterface {
            /** @param array<string, list<CardMilestone>> $milestones */
            public function __construct(
                private array $milestones,
            ) {
            }

            #[\Override]
            public function milestonesOf(Project $project, array $cardIds, TemplateMetricSet $templates): array
            {
                return $this->milestones;
            }
        };
        $templates = $this->createStub(TemplateMetrics::class);
        $templates->method('forProject')->willReturn(new TemplateMetricSet());
        $workRequests = self::getContainer()->get(WorkRequestRepository::class);
        self::assertInstanceOf(WorkRequestRepository::class, $workRequests);
        $values = new TemplateMetricValues($workRequests, $reports, $templates, [$milestones]);
        $bridges = self::getContainer()->get(BridgeRepository::class);
        self::assertInstanceOf(BridgeRepository::class, $bridges);
        $readings = self::getContainer()->get(BridgeAccountReadingRepository::class);
        self::assertInstanceOf(BridgeAccountReadingRepository::class, $readings);
        $handler = new MetricQueryHandler(new MetricRowSource($facts, $finished, $reports, $bucketTimes, $workerRuns, $values, $bridges, $readings), new MockClock(self::NOW.' UTC'));

        return $handler(new MetricQueryCommand($this->project, $unit, $shape, $statistic, $group, $range, $bucket, $compare));
    }

    private function finishedCard(int $number, string $completedAt): Uuid
    {
        $id = Uuid::v7();
        $this->finished[] = new FinishedCard($id, $number, 'Card '.$number, new \DateTimeImmutable($completedAt));

        return $id;
    }

    private function fact(
        Uuid $subjectId,
        ?string $endedAt,
        ?int $cost = 0,
        ?string $startedAt = '2026-09-01 08:00:00',
        ?string $receivedAt = null,
        string $outcome = 'succeeded',
        ?string $workKind = 'implement',
        string $subjectType = 'card',
        ?int $cardNumber = null,
        string $kind = 'worker',
        ?Uuid $runId = null,
        ?string $harness = null,
        ?string $account = null,
        ?string $harnessVersion = null,
        ?string $cliVersion = null,
        ?string $skillVersion = null,
        ?string $variant = null,
        ?float $sessionUsageDelta = null,
        ?float $weekUsageDelta = null,
    ): Uuid {
        $runId ??= Uuid::v7();
        $this->em->getConnection()->insert('bridge_worker_run_facts', [
            'run_id' => (string) $runId,
            'project_id' => (string) $this->project->id,
            'subject_type' => $subjectType,
            'subject_id' => (string) $subjectId,
            'card_number' => $cardNumber,
            'kind' => $kind,
            'work_kind' => $workKind,
            'outcome' => $outcome,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'received_at' => $receivedAt ?? $endedAt,
            'cost_micro_usd' => $cost,
            'usage_source' => null === $cost ? null : 'reported',
            'harness' => $harness,
            'account' => $account,
            'harness_version' => $harnessVersion,
            'cli_version' => $cliVersion,
            'skill_version' => $skillVersion,
            'variant' => $variant,
            'session_usage_delta' => $sessionUsageDelta,
            'week_usage_delta' => $weekUsageDelta,
        ]);

        return $runId;
    }
}
