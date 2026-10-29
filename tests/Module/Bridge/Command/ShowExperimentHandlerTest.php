<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Module\Bridge\Command\ExperimentReportView;
use App\Module\Bridge\Command\ShowExperimentCommand;
use App\Module\Bridge\Command\ShowExperimentHandler;
use App\Module\Bridge\Entity\ExperimentDefinition;
use App\Module\Bridge\Entity\ExperimentPin;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunUsage;
use App\Module\Bridge\Experiment\CardColumn;
use App\Module\Bridge\Experiment\CardReportSourceInterface;
use App\Module\Bridge\Experiment\Confidence;
use App\Module\Bridge\Experiment\ControlResult;
use App\Module\Bridge\Experiment\ExperimentArm;
use App\Module\Bridge\Experiment\ExperimentCard;
use App\Module\Bridge\Experiment\ExperimentComparison;
use App\Module\Bridge\Experiment\ExperimentControl;
use App\Module\Bridge\Experiment\ExperimentGuard;
use App\Module\Bridge\Experiment\ExperimentMetric;
use App\Module\Bridge\Experiment\ExperimentVariant;
use App\Module\Bridge\Experiment\GoalDirection;
use App\Module\Bridge\Experiment\GoalState;
use App\Module\Bridge\Experiment\Interval;
use App\Module\Bridge\Experiment\LeftOutReason;
use App\Module\Bridge\Experiment\Stats;
use App\Module\Bridge\Metric\CardMilestone;
use App\Module\Bridge\Metric\CardMilestoneSourceInterface;
use App\Module\Bridge\Metric\MetricRowSource;
use App\Module\Bridge\Metric\MetricValueType;
use App\Module\Bridge\Metric\TemplateMetricValues;
use App\Module\Bridge\Repository\ExperimentDefinitionRepository;
use App\Module\Bridge\Repository\ExperimentPinRepository;
use App\Module\Bridge\Repository\WorkerRunBucketTimeRepository;
use App\Module\Bridge\Repository\WorkerRunFactRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Service\BucketRule;
use App\Module\Bridge\Service\BucketRuleSourceInterface;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkerRunUsageSource;
use App\Module\Bridge\ValueObject\WorkRequestContext;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Bridge\View\CardTitleSourceInterface;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\CountFilter;
use App\Module\Workflow\Contract\DurationEvent;
use App\Module\Workflow\Contract\MetricType;
use App\Module\Workflow\Contract\TemplateMetric;
use App\Module\Workflow\Contract\TemplateMetrics;
use App\Module\Workflow\Contract\TemplateMetricSet;
use App\Module\Workflow\Contract\TemplateOutcome;
use App\Tests\Module\Bridge\BridgeScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ShowExperimentHandlerTest extends KernelTestCase
{
    use BridgeScenario;

    private const string EXPERIMENT = 'model-test';

    private EntityManagerInterface $em;
    private Project $project;
    private \DateTimeImmutable $start;
    private ?\DateTimeImmutable $historyStart;

    /** @var array<string, CardOutcomeFixture> */
    private array $outcomes = [];

    /** @var array<string, CardColumn> */
    private array $columns = [];

    /** @var array<string, true> the cards whose outcome already reached the database */
    private array $stored = [];

    /** @var array<string, list<CardMilestone>> */
    private array $milestones = [];

    private TemplateMetricSet $templateSet;

    /** @var list<BucketRuleSourceInterface> */
    private array $bucketRuleSources = [];

    /** The outcome a merged fixture card carries. */
    private string $outcomeKey = 'merged';

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = $this->em();
        $owner = $this->user($this->em, 'experiment-'.uniqid().'@example.com');
        $this->project = $this->project($this->em, $owner, 'Experiment');
        $this->start = new \DateTimeImmutable('2026-09-01 10:00:00');
        $this->historyStart = $this->start->modify('-30 days');
        $this->templateSet = self::lifecycleSet();
    }

    /** The outcomes and metrics of the lifecycle template, as the experiment table reads them. */
    private static function lifecycleSet(): TemplateMetricSet
    {
        return new TemplateMetricSet(
            [
                new TemplateMetric('fix-rounds', 'Fix rounds', 'The fix rounds a finished card needed.', MetricType::Count, new CountFilter(role: 'fix'), 'reason'),
                new TemplateMetric('merge-rate', 'Merge rate', 'The share of finished cards that the merge rule ended.', MetricType::Rate, outcome: 'merged'),
                new TemplateMetric('hours-to-merge', 'Hours to merge', 'The hours from the first pull request opening to the merge.', MetricType::Duration, from: new DurationEvent(DurationEvent::PR_OPENED), to: new DurationEvent(DurationEvent::PR_MERGED)),
            ],
            [new TemplateOutcome('merged', true)],
        );
    }

    public function test_an_experiment_with_no_run_and_no_pin_has_no_report(): void
    {
        $this->experimentRun(Uuid::v7(), 'a', experiment: 'another-test');

        self::assertNull($this->show());
    }

    public function test_a_pin_alone_makes_a_report(): void
    {
        $card = Uuid::v7();
        $this->em->persist(new ExperimentPin($this->project, $card, self::EXPERIMENT, 'a'));
        $this->em->flush();

        $view = $this->show();

        self::assertNotNull($view);
        self::assertEquals([new ExperimentCard($card, null, null, 'a', null, 0, 0, null, null, [LeftOutReason::NoRun])], $view->cards);
        self::assertSame(['a'], array_map(static fn (ExperimentVariant $variant): string => $variant->name, $view->variants));
    }

    public function test_it_keeps_every_reason_to_leave_a_card_out(): void
    {
        $switched = Uuid::v7();
        $this->experimentRun($switched, 'a', at: '+1 hour');
        $this->experimentRun($switched, 'a', at: '+2 hours', switchedFrom: 'b');

        $mixed = Uuid::v7();
        $this->experimentRun($mixed, 'a', at: '+1 hour');
        $this->experimentRun($mixed, 'b', at: '+2 hours');

        $switchedAndMixed = Uuid::v7();
        $this->experimentRun($switchedAndMixed, 'b', at: '+1 hour');
        $this->experimentRun($switchedAndMixed, 'a', at: '+2 hours', switchedFrom: 'b');

        $beforeTest = Uuid::v7();
        $this->plainRun($beforeTest, 'implement', '+1 hour');
        $this->experimentRun($beforeTest, 'a', at: '+2 hours');

        $otherKind = Uuid::v7();
        $this->plainRun($otherKind, 'design', '+1 hour');
        $this->experimentRun($otherKind, 'a', at: '+2 hours');

        $plainAfter = Uuid::v7();
        $this->experimentRun($plainAfter, 'a', at: '+1 hour');
        $this->plainRun($plainAfter, 'implement', '+2 hours');

        $otherExperiment = Uuid::v7();
        $this->experimentRun($otherExperiment, 'opus', at: '+1 hour', experiment: 'older-trial');
        $this->experimentRun($otherExperiment, 'a', at: '+2 hours');

        $noHistory = Uuid::v7();
        $this->experimentRun($noHistory, 'a', at: '-31 days');

        $view = $this->show();

        self::assertNotNull($view);
        self::assertSame([
            (string) $switched => ['switched'],
            (string) $mixed => ['mixed'],
            (string) $switchedAndMixed => ['switched', 'mixed'],
            (string) $beforeTest => ['before-test'],
            (string) $otherKind => [],
            (string) $plainAfter => ['covered-without-variant'],
            (string) $noHistory => ['no-history'],
            (string) $otherExperiment => ['before-test'],
        ], $this->reasonsById($view, [$switched, $mixed, $switchedAndMixed, $beforeTest, $otherKind, $plainAfter, $noHistory, $otherExperiment]));
        self::assertSame(1, $view->includedCards);
        self::assertSame(7, $view->leftOutCards);
        // A left-out card counts in no variant.
        self::assertSame([1, 0], array_map(static fn (ExperimentVariant $variant): int => $variant->cards, $view->variants));
    }

    public function test_a_card_has_no_history_when_the_project_has_none(): void
    {
        $this->historyStart = null;
        $card = Uuid::v7();
        $this->experimentRun($card, 'a', at: '+1 hour');

        $view = $this->show();

        self::assertNotNull($view);
        self::assertSame([LeftOutReason::NoHistory], $view->cards[0]->leftOut);
    }

    public function test_a_card_with_a_pin_and_no_run_is_left_out_as_never_run_with_or_without_history(): void
    {
        $card = Uuid::v7();
        $this->em->persist(new ExperimentPin($this->project, $card, self::EXPERIMENT, 'a'));
        $this->em->flush();
        $this->outcomes[(string) $card] = new CardOutcomeFixture(merged: true);

        $withHistory = $this->show();
        $this->historyStart = null;
        $withoutHistory = $this->show();

        self::assertNotNull($withHistory);
        self::assertNotNull($withoutHistory);
        self::assertSame([LeftOutReason::NoRun], $withHistory->cards[0]->leftOut);
        self::assertSame([LeftOutReason::NoRun], $withoutHistory->cards[0]->leftOut);
        self::assertSame(0, $withHistory->includedCards);
        self::assertSame(1, $withHistory->leftOutCards);
        self::assertNull($withHistory->metrics[ExperimentMetric::MERGE_RATE]->for('a'));
    }

    public function test_a_merged_card_with_no_usage_stays_out_of_the_cost_and_token_samples_only(): void
    {
        $withUsage = Uuid::v7();
        $this->seedUsage($this->em, $this->experimentRun($withUsage, 'a', at: '+1 hour'), costUsd: '1.000000');
        $noUsage = Uuid::v7();
        $this->experimentRun($noUsage, 'a', at: '+2 hours');
        $this->outcomes[(string) $withUsage] = new CardOutcomeFixture(fixRounds: ['conflict' => 2], merged: true);
        $this->outcomes[(string) $noUsage] = new CardOutcomeFixture(merged: true);

        $view = $this->show();

        self::assertNotNull($view);
        self::assertEquals(new Interval(1.0, 1.0, 1.0), $view->metrics[ExperimentMetric::COST]->for('a'));
        self::assertEquals(new Interval(20.0, 20.0, 20.0), $view->metrics[ExperimentMetric::OUTPUT_TOKENS]->for('a'));
        self::assertEquals(Stats::wilson(2, 2, Confidence::forComparisons(1)), $view->metrics[ExperimentMetric::MERGE_RATE]->for('a'));
        self::assertSame(1.0, $view->metrics[ExperimentMetric::FIX_ROUNDS]->for('a')?->point);
        self::assertSame(1_000_000, $view->variants[0]->costMicros);
        self::assertSame([(string) $noUsage => null, (string) $withUsage => 1_000_000], array_combine(
            array_map(static fn (ExperimentCard $row): string => (string) $row->cardId, $view->cards),
            array_map(static fn (ExperimentCard $row): ?int => $row->costMicros, $view->cards),
        ));
    }

    public function test_it_ignores_the_rows_of_another_project(): void
    {
        $card = Uuid::v7();
        $this->seedUsage($this->em, $this->experimentRun($card, 'a'), costUsd: '1.000000');

        $other = $this->project($this->em, $this->user($this->em, 'experiment-other-'.uniqid().'@example.com'), 'Other experiment');
        $foreign = $this->seedRun($this->em, $other, receivedAt: $this->start->modify('+2 hours'), cardId: $card, state: WorkerRunState::Succeeded, runKey: Uuid::v7());
        $foreign->experiment = self::EXPERIMENT;
        $foreign->variant = 'b';
        $foreign->requestedModel = 'foreign-model';
        $this->seedUsage($this->em, $foreign, costUsd: '9.000000');
        $this->em->persist(new ExperimentPin($other, Uuid::v7(), self::EXPERIMENT, 'b'));
        $this->em->flush();

        $view = $this->show();

        self::assertNotNull($view);
        self::assertEquals([new ExperimentVariant('a', ['claude-opus-5-5'], null, 1, 0, 1, 1_000_000, 1_000_000)], $view->variants);
        self::assertSame([(string) $card], array_map(static fn (ExperimentCard $row): string => (string) $row->cardId, $view->cards));
        self::assertSame(1_000_000, $view->cards[0]->costMicros);
    }

    public function test_the_cards_tab_skips_the_metrics(): void
    {
        $card = Uuid::v7();
        $this->seedUsage($this->em, $this->experimentRun($card, 'a'), costUsd: '1.000000');
        $this->outcomes[(string) $card] = new CardOutcomeFixture(merged: true);

        $view = $this->show(withMetrics: false);

        self::assertNotNull($view);
        self::assertSame([], $view->metrics);
        self::assertNull($view->headline);
        self::assertSame(1, $view->variants[0]->finishedCards);
        self::assertSame([(string) $card], array_map(static fn (ExperimentCard $row): string => (string) $row->cardId, $view->cards));
    }

    public function test_it_compares_the_variants_on_each_metric(): void
    {
        $definition = new ExperimentDefinition($this->project, self::EXPERIMENT, [['name' => 'a', 'weight' => 70], ['name' => 'b', 'weight' => 30]]);
        $definition->baseline = 'a';
        $this->em->persist($definition);
        $merged = static fn (array $fixRounds, ?float $hours = 2.0): CardOutcomeFixture => new CardOutcomeFixture(
            fixRounds: $fixRounds,
            merged: true,
            hoursToMerge: $hours,
        );

        // Variant a: five merged cards, one finished by hand, one still open.
        $aFixRounds = [[], ['conflict' => 1], ['conflict' => 1, 'checks-failed' => 1], ['checks-failed' => 1], ['changes-requested' => 1]];
        foreach ($aFixRounds as $i => $fixRounds) {
            $card = Uuid::v7();
            $run = $this->experimentRun($card, 'a', at: \sprintf('+%d hours', $i + 1));
            $this->seedUsage($this->em, $run, costUsd: '1.000000');
            $this->outcomes[(string) $card] = $merged($fixRounds, 0 === $i ? null : 2.0);
            if (0 === $i) {
                foreach (['+20 hours' => WorkerRunState::Blocked, '+21 hours' => WorkerRunState::Failed, '+22 hours' => WorkerRunState::Stopped] as $at => $state) {
                    $stopped = $this->experimentRun($card, 'a', at: $at, state: $state);
                    $stopped->usageSource = WorkerRunUsageSource::Reported;
                    $this->em->persist(new WorkerRunUsage($stopped, $run->project, WorkSubject::CARD, $card, $stopped->workKind, 'claude-opus-5-5', WorkerRunUsageSource::Reported, 0, 0, 0, 0, '0.000000'));
                }
                // A row whose run is gone counts nowhere, and a run outside the experiment counts in the runs and the tokens.
                $this->em->persist(new WorkerRunUsage(null, $run->project, WorkSubject::CARD, $card, 'build', 'claude-opus-5-5', WorkerRunUsageSource::Reported, 1, 1, 0, 0, '5.000000'));
                $this->seedUsage($this->em, $this->plainRun($card, 'design', '-1 hour'), costUsd: '0.000000');
            }
        }
        $byHand = Uuid::v7();
        $this->experimentRun($byHand, 'a', at: '+6 hours');
        $this->columns[(string) $byHand] = new CardColumn('board.card.status.done', true);
        $open = Uuid::v7();
        $this->experimentRun($open, 'a', at: '+7 hours');
        $this->columns[(string) $open] = new CardColumn('board.card.status.in-progress', false);

        // Variant b: five merged cards, each with one fix round, at half the cost.
        for ($i = 0; $i < 5; ++$i) {
            $card = Uuid::v7();
            $this->seedUsage($this->em, $this->experimentRun($card, 'b', at: \sprintf('+%d hours', $i + 10), model: 'claude-sonnet-5'), costUsd: '0.500000');
            $this->outcomes[(string) $card] = $merged(['conflict' => 1], 4.0);
        }
        $this->em->flush();

        $view = $this->show();

        self::assertNotNull($view);
        self::assertEquals([
            new ExperimentVariant('a', ['claude-opus-5-5'], 70, 7, 6, 11, 5_000_000, 5_000_000),
            new ExperimentVariant('b', ['claude-sonnet-5'], 30, 5, 5, 5, 2_500_000, 2_500_000),
        ], $view->variants);
        self::assertSame([ExperimentMetric::MERGE_RATE, ExperimentMetric::STOP_RATE, ExperimentMetric::FIX_ROUNDS, ExperimentMetric::COST, ExperimentMetric::COVERED_COST, ExperimentMetric::OUTPUT_TOKENS, ExperimentMetric::HOURS_TO_MERGE], array_keys($view->metrics));

        $mergeRate = $view->metrics[ExperimentMetric::MERGE_RATE];
        self::assertEquals(Stats::wilson(5, 6, Confidence::forComparisons(1)), $mergeRate->for('a'));
        self::assertEquals(Stats::wilson(5, 5, Confidence::forComparisons(1)), $mergeRate->for('b'));
        self::assertFalse($mergeRate->clear);

        $stopRate = $view->metrics[ExperimentMetric::STOP_RATE];
        self::assertEquals(Stats::wilson(2, 9, Confidence::forComparisons(1)), $stopRate->for('a'));
        self::assertEquals(Stats::wilson(0, 5, Confidence::forComparisons(1)), $stopRate->for('b'));

        $fixRounds = $view->metrics[ExperimentMetric::FIX_ROUNDS];
        self::assertEquals(Stats::bootstrapMean([0, 1, 2, 1, 1], 'model-test:fix-rounds:a', Confidence::forComparisons(1)), $fixRounds->for('a'));
        self::assertSame(1.0, $fixRounds->for('b')?->point);
        self::assertTrue($fixRounds->clear);
        self::assertSame(['changes-requested', 'checks-failed', 'conflict'], array_map(static fn (ExperimentMetric $part): string => $part->key, $fixRounds->parts));
        self::assertEquals(Stats::bootstrapMean([0, 1, 1, 0, 0], 'model-test:fix-rounds:conflict:a', Confidence::forComparisons(1)), $fixRounds->parts[2]->for('a'));
        self::assertSame(0.0, $fixRounds->parts[0]->for('b')?->point);

        $cost = $view->metrics[ExperimentMetric::COST];
        self::assertEquals(new Interval(1.0, 1.0, 1.0), $cost->for('a'));
        self::assertEquals(new Interval(0.5, 0.5, 0.5), $cost->for('b'));
        self::assertTrue($cost->clear);

        self::assertSame(24.0, $view->metrics[ExperimentMetric::OUTPUT_TOKENS]->for('a')?->point);

        $hours = $view->metrics[ExperimentMetric::HOURS_TO_MERGE];
        self::assertEquals(new Interval(2.0, 2.0, 2.0), $hours->for('a'));
        self::assertEquals(new Interval(4.0, 4.0, 4.0), $hours->for('b'));

        self::assertNotNull($view->headline);
        $goal = $view->headline->goal;
        self::assertSame(ExperimentMetric::COST, $goal->metric->key);
        self::assertSame(GoalDirection::Lower, $goal->direction);
        self::assertNull($goal->problem);
        self::assertSame('a', $view->headline->baseline);
        self::assertEquals(new ExperimentArm('a', 1.0, 5), $view->headline->baselineArm);
        self::assertCount(1, $view->headline->comparisons);
        [$comparison] = $view->headline->comparisons;
        self::assertSame('b', $comparison->variant);
        self::assertSame(GoalState::Clear, $comparison->state);
        self::assertEquals(new ExperimentArm('b', 0.5, 5), $comparison->arm);
        self::assertTrue($comparison->relative);
        self::assertNotNull($comparison->change);
        self::assertEqualsWithDelta(-0.5, $comparison->change->point, 1e-12);
        self::assertSame([ExperimentMetric::MERGE_RATE, ExperimentMetric::FIX_ROUNDS], array_map(static fn (ExperimentGuard $guard): string => $guard->metric->key, $view->headline->guards));
        self::assertEqualsWithDelta(5 / 6, $view->headline->guards[0]->baselinePoint, 1e-12);
        self::assertSame(1.0, $comparison->guards[0]->point);
        self::assertEquals(Stats::newcombe(5, 6, 5, 5, Confidence::forComparisons(1)), $comparison->guards[0]->change);
        self::assertEquals(Stats::differenceRange([0, 1, 2, 1, 1], [1, 1, 1, 1, 1], 'model-test:fix-rounds:guard:b', Confidence::forComparisons(1)), $comparison->guards[1]->change);
        self::assertSame([], $view->unknownGuards);
    }

    /**
     * @param array{goal?: array{metric: string, better?: string}, guards?: list<string>}|null $headline
     * @param list<string>|null                                                                $metrics
     */
    private function seedHeadlineExperiment(?array $headline, ?array $metrics = null, string $costA = '2.000000', string $costB = '1.000000', int $mergedA = 5): void
    {
        $definition = new ExperimentDefinition($this->project, self::EXPERIMENT, [['name' => 'a', 'weight' => 1], ['name' => 'b', 'weight' => 1]]);
        $definition->baseline = 'a';
        $definition->headline = $headline;
        $definition->metrics = $metrics;
        $this->em->persist($definition);
        foreach (['a' => $costA, 'b' => $costB] as $variant => $cost) {
            for ($i = 0; $i < 5; ++$i) {
                $card = Uuid::v7();
                $this->seedUsage($this->em, $this->experimentRun($card, $variant, at: \sprintf('+%d hours', $i + 1)), costUsd: $cost);
                $merged = 'a' !== $variant || $i < $mergedA;
                $this->outcomes[(string) $card] = new CardOutcomeFixture(merged: $merged);
                if (!$merged) {
                    $this->columns[(string) $card] = new CardColumn('board.card.status.done', true);
                }
            }
        }
        $this->em->flush();
    }

    /**
     * @param list<array{metric: string, variant: string, expect: string, share: float}> $controls
     * @param array<string, list<?array<string, int>>>                                   $events   variant => the harness events of the one run of each finished card, null for a run that reported none
     */
    private function seedControlExperiment(array $controls, array $events): void
    {
        $definition = new ExperimentDefinition($this->project, self::EXPERIMENT, [['name' => 'a', 'weight' => 1], ['name' => 'b', 'weight' => 1]]);
        $definition->baseline = 'a';
        $definition->headline = ['goal' => ['metric' => 'cost', 'better' => 'lower'], 'controls' => $controls];
        $this->em->persist($definition);
        $hour = 0;
        foreach ($events as $variant => $runs) {
            foreach ($runs as $map) {
                $card = Uuid::v7();
                $run = $this->experimentRun($card, $variant, at: \sprintf('+%d hours', ++$hour));
                $run->harnessEvents = $map;
                $this->seedUsage($this->em, $run, costUsd: 'a' === $variant ? '2.000000' : '1.000000');
                $this->outcomes[(string) $card] = new CardOutcomeFixture(merged: true);
            }
        }
        $this->em->flush();
    }

    public function test_a_failed_control_holds_the_goal_and_every_card_stays_in_the_figures(): void
    {
        $this->seedControlExperiment(
            [['metric' => 'harness-events:diagnostics', 'variant' => 'a', 'expect' => '> 0', 'share' => 0.5]],
            ['a' => [['diagnostics' => 2], [], [], [], null], 'b' => [[], [], []]],
        );

        $view = $this->show();

        self::assertNotNull($view);
        self::assertNotNull($view->headline);
        [$control] = $view->headline->controls;
        self::assertSame(ControlResult::Failed, $control->result);
        self::assertSame(0.25, $control->passingShare);
        self::assertSame(4, $control->cardsWithData);
        self::assertSame(5, $control->cardsFinished);
        [$comparison] = $view->headline->comparisons;
        self::assertSame(GoalState::Held, $comparison->state);
        self::assertSame(ControlResult::Failed, $comparison->hold?->reason);
        self::assertNull($comparison->change);
        self::assertSame([5, 3], array_map(static fn (ExperimentVariant $variant): int => $variant->cards, $view->variants));
        self::assertSame([2.0, 1.0], [$view->headline->baselineArm?->point, $comparison->arm->point]);
    }

    public function test_a_passing_control_leaves_the_goal_to_the_data(): void
    {
        $this->seedControlExperiment(
            [['metric' => 'harness-events:diagnostics', 'variant' => 'a', 'expect' => '> 0', 'share' => 0.5]],
            ['a' => [['diagnostics' => 1], ['diagnostics' => 3], []], 'b' => [[], []]],
        );

        $view = $this->show();

        self::assertNotNull($view);
        self::assertNotNull($view->headline);
        self::assertSame(ControlResult::Passed, $view->headline->controls[0]->result);
        self::assertEqualsWithDelta(2 / 3, $view->headline->controls[0]->passingShare, 1e-9);
        self::assertNull($view->headline->comparisons[0]->hold);
        self::assertSame(GoalState::NotEnoughCards, $view->headline->comparisons[0]->state);
    }

    public function test_a_control_reads_only_the_experiment_runs_of_a_card(): void
    {
        $definition = new ExperimentDefinition($this->project, self::EXPERIMENT, [['name' => 'a', 'weight' => 1]]);
        $definition->headline = ['controls' => [
            ['metric' => 'harness-events:diagnostics', 'variant' => 'a', 'expect' => '> 0', 'share' => 1.0],
            ['metric' => 'input-tokens', 'variant' => 'a', 'expect' => '< 150', 'share' => 1.0],
            ['metric' => 'cost', 'variant' => 'a', 'expect' => '< 3', 'share' => 1.0],
            ['metric' => 'runs', 'variant' => 'a', 'expect' => '== 1', 'share' => 1.0],
        ]];
        $this->em->persist($definition);
        $card = Uuid::v7();
        $run = $this->experimentRun($card, 'a', at: '+1 hour');
        $run->harnessEvents = [];
        $this->seedUsage($this->em, $run, costUsd: '2.000000', inputTokens: 100);
        $review = $this->plainRun($card, 'review', '+2 hours');
        $review->harnessEvents = ['diagnostics' => 4];
        $this->seedUsage($this->em, $review, costUsd: '2.000000', inputTokens: 100);
        $this->outcomes[(string) $card] = new CardOutcomeFixture(merged: true);
        $this->em->flush();

        $view = $this->show();

        self::assertNotNull($view);
        self::assertNotNull($view->headline);
        self::assertSame([], $view->cards[0]->leftOut);
        self::assertSame([ControlResult::Failed, ControlResult::Passed, ControlResult::Passed, ControlResult::Passed], array_map(static fn ($control): ControlResult => $control->result, $view->headline->controls));
    }

    public function test_a_control_with_no_reported_event_has_no_data_and_holds_the_goal(): void
    {
        $this->seedControlExperiment(
            [['metric' => 'harness-events:diagnostics', 'variant' => 'a', 'expect' => '> 0', 'share' => 0.5]],
            ['a' => [null, null], 'b' => [[]]],
        );

        $view = $this->show();

        self::assertNotNull($view);
        self::assertNotNull($view->headline);
        self::assertSame(ControlResult::NoData, $view->headline->controls[0]->result);
        self::assertSame(0, $view->headline->controls[0]->cardsWithData);
        self::assertSame(ControlResult::NoData, $view->headline->comparisons[0]->hold?->reason);
    }

    public function test_a_card_with_no_value_is_left_out_of_the_share(): void
    {
        $this->seedControlExperiment(
            [['metric' => 'harness-events:diagnostics', 'variant' => 'a', 'expect' => '> 0', 'share' => 1.0]],
            ['a' => [['diagnostics' => 1], null, null], 'b' => [[]]],
        );

        $view = $this->show();

        self::assertNotNull($view);
        self::assertNotNull($view->headline);
        self::assertSame(ControlResult::Passed, $view->headline->controls[0]->result);
        self::assertSame(1, $view->headline->controls[0]->cardsWithData);
        self::assertSame(3, $view->headline->controls[0]->cardsFinished);
    }

    public function test_a_bucket_nobody_declared_is_an_unknown_metric_and_a_declared_one_is_not(): void
    {
        $this->bucketRuleSources = [new class implements BucketRuleSourceInterface {
            #[\Override]
            public function rulesFor(Project $project): array
            {
                return [new BucketRule('LSP', 'lsp')];
            }
        }];
        $this->seedControlExperiment([
            ['metric' => 'bucket-calls:lps', 'variant' => 'a', 'expect' => '> 0', 'share' => 0.5],
            ['metric' => 'bucket-calls:lsp', 'variant' => 'a', 'expect' => '> 0', 'share' => 0.5],
            ['metric' => 'bucket-calls:other', 'variant' => 'a', 'expect' => '> 0', 'share' => 0.5],
            ['metric' => 'no-such-metric', 'variant' => 'a', 'expect' => '> 0', 'share' => 0.5],
            ['metric' => 'harness-events:never-seen', 'variant' => 'a', 'expect' => '> 0', 'share' => 0.5],
        ], ['a' => [null], 'b' => [null]]);

        $view = $this->show();

        self::assertNotNull($view);
        self::assertNotNull($view->headline);
        self::assertSame(
            [ControlResult::UnknownMetric, ControlResult::NoData, ControlResult::NoData, ControlResult::UnknownMetric, ControlResult::NoData],
            array_map(static fn (ExperimentControl $control): ControlResult => $control->result, $view->headline->controls),
        );
        self::assertSame(ControlResult::UnknownMetric, $view->headline->comparisons[0]->hold?->reason);
        self::assertSame(['bucket-calls:lps', 'no-such-metric'], array_map(static fn (ExperimentControl $control): string => $control->metric, $view->headline->comparisons[0]->hold->controls));
    }

    public function test_a_bucket_with_stored_rows_is_a_known_metric(): void
    {
        $this->seedControlExperiment(
            [['metric' => 'bucket-calls:tests', 'variant' => 'a', 'expect' => '>= 3', 'share' => 1.0]],
            ['a' => [[], []], 'b' => [[]]],
        );
        $run = self::getContainer()->get(WorkerRunRepository::class)->findOneBy(['variant' => 'a']);
        self::assertNotNull($run);
        $this->em->getConnection()->insert('bridge_worker_run_bucket_times', ['id' => (string) Uuid::v7(), 'run_id' => (string) $run->id, 'bucket' => 'tests', 'ms' => 10, 'ms_with_subagents' => 10, 'calls' => 3]);

        $view = $this->show();

        self::assertNotNull($view);
        self::assertNotNull($view->headline);
        self::assertSame(ControlResult::Passed, $view->headline->controls[0]->result);
        self::assertSame(1, $view->headline->controls[0]->cardsWithData);
    }

    public function test_a_control_reads_a_template_metric_and_carries_its_label(): void
    {
        $this->seedControlExperiment(
            [['metric' => 'merge-rate', 'variant' => 'a', 'expect' => '== 1', 'share' => 1.0]],
            ['a' => [[], []], 'b' => [[]]],
        );

        $view = $this->show();

        self::assertNotNull($view);
        self::assertNotNull($view->headline);
        self::assertSame(ControlResult::Passed, $view->headline->controls[0]->result);
        self::assertSame('Merge rate', $view->headline->controls[0]->templateLabel);
    }

    public function test_no_controls_when_the_command_skips_the_metrics(): void
    {
        $this->seedControlExperiment(
            [['metric' => 'harness-events:diagnostics', 'variant' => 'a', 'expect' => '> 0', 'share' => 0.5]],
            ['a' => [[]], 'b' => [[]]],
        );

        $view = $this->show(withMetrics: false);

        self::assertNotNull($view);
        self::assertNull($view->headline);
    }

    public function test_a_goal_on_a_ratio_compares_the_variant_in_points(): void
    {
        $this->seedHeadlineExperiment(['goal' => ['metric' => 'merge-rate', 'better' => 'higher'], 'guards' => ['cost']], ['fix-rounds'], mergedA: 2);

        $view = $this->show();

        self::assertNotNull($view);
        self::assertSame([ExperimentMetric::FIX_ROUNDS], array_keys($view->metrics));
        self::assertNotNull($view->headline);
        $goal = $view->headline->goal;
        self::assertSame(ExperimentMetric::MERGE_RATE, $goal->metric->key);
        self::assertSame(GoalDirection::Higher, $goal->direction);
        [$comparison] = $view->headline->comparisons;
        self::assertFalse($comparison->relative);
        self::assertEquals(Stats::newcombe(2, 5, 5, 5, Confidence::forComparisons(1)), $comparison->change);
        self::assertSame([ExperimentMetric::COST], array_map(static fn (ExperimentGuard $guard): string => $guard->metric->key, $view->headline->guards));
        self::assertEquals(Stats::differenceRange([2.0, 2.0, 2.0, 2.0, 2.0], [1.0, 1.0, 1.0, 1.0, 1.0], 'model-test:cost:guard:b', Confidence::forComparisons(1)), $comparison->guards[0]->change);
    }

    public function test_the_headline_is_not_clear_when_the_ranges_overlap(): void
    {
        $this->seedHeadlineExperiment(['goal' => ['metric' => 'merge-rate', 'better' => 'higher']], mergedA: 3);

        $view = $this->show();

        self::assertNotNull($view);
        self::assertNotNull($view->headline);
        self::assertSame(GoalState::NotClear, $view->headline->comparisons[0]->state);
        self::assertNotNull($view->headline->comparisons[0]->change);
    }

    public function test_equal_points_give_no_change(): void
    {
        $this->seedHeadlineExperiment(null, costA: '1.000000', costB: '1.000000');

        $view = $this->show();

        self::assertNotNull($view);
        self::assertNotNull($view->headline);
        self::assertSame(GoalState::NotClear, $view->headline->comparisons[0]->state);
        self::assertEqualsWithDelta(0.0, $view->headline->comparisons[0]->change?->point, 1e-12);
    }

    public function test_the_default_guards_leave_out_the_goal(): void
    {
        $this->seedHeadlineExperiment(['goal' => ['metric' => 'merge-rate', 'better' => 'higher']], ['fix-rounds']);

        $view = $this->show();

        self::assertNotNull($view);
        self::assertNotNull($view->headline);
        self::assertNotContains(ExperimentMetric::MERGE_RATE, array_map(static fn (ExperimentGuard $guard): string => $guard->metric->key, $view->headline->guards));
    }

    public function test_empty_guards_show_no_guards(): void
    {
        $this->seedHeadlineExperiment(['guards' => []]);

        $view = $this->show();

        self::assertNotNull($view);
        self::assertNotNull($view->headline);
        self::assertSame([], $view->headline->guards);
        self::assertSame(ExperimentMetric::COST, $view->headline->goal->metric->key);
    }

    public function test_a_goal_that_no_metric_has_names_the_problem(): void
    {
        $this->seedHeadlineExperiment(['goal' => ['metric' => 'no-such-metric', 'better' => 'higher'], 'guards' => ['bucket-time', 'merge-rate', 'no-such-metric', 'also-missing']]);

        $view = $this->show();

        self::assertNotNull($view);
        self::assertNotNull($view->headline);
        $goal = $view->headline->goal;
        self::assertSame('no-such-metric', $goal->metric->key);
        self::assertSame(GoalState::UnknownMetric, $goal->problem);
        self::assertSame(GoalState::UnknownMetric, $view->headline->comparisons[0]->state);
        self::assertNull($view->headline->comparisons[0]->change);
        self::assertSame([ExperimentMetric::MERGE_RATE], array_map(static fn (ExperimentGuard $guard): string => $guard->metric->key, $view->headline->guards));
        self::assertSame(['bucket-time', 'no-such-metric', 'also-missing'], $view->unknownGuards);
        self::assertSame([], $view->unknownMetrics);
    }

    public function test_a_goal_with_no_direction_names_the_problem(): void
    {
        $this->seedHeadlineExperiment(['goal' => ['metric' => 'cost']]);

        $view = $this->show();

        self::assertNotNull($view);
        self::assertNotNull($view->headline);
        $goal = $view->headline->goal;
        self::assertSame(ExperimentMetric::COST, $goal->metric->key);
        self::assertSame(GoalState::NoDirection, $goal->problem);
        self::assertNull($goal->direction);
        self::assertSame(GoalState::NoDirection, $view->headline->comparisons[0]->state);
        self::assertSame([], $view->unknownGuards);
    }

    public function test_a_goal_that_is_also_a_guard_does_not_break_the_headline(): void
    {
        $this->seedHeadlineExperiment(['goal' => ['metric' => 'cost', 'better' => 'lower'], 'guards' => ['cost']]);

        $view = $this->show();

        self::assertNotNull($view);
        self::assertNotNull($view->headline);
        self::assertSame(GoalState::Clear, $view->headline->comparisons[0]->state);
        self::assertCount(1, $view->headline->guards);
    }

    public function test_the_goal_and_the_guards_do_not_add_rows_to_the_metrics(): void
    {
        $this->seedHeadlineExperiment(['goal' => ['metric' => 'cost', 'better' => 'lower'], 'guards' => ['merge-rate']], ['stop-rate']);

        $view = $this->show();

        self::assertNotNull($view);
        self::assertSame([ExperimentMetric::STOP_RATE], array_keys($view->metrics));
        self::assertNotNull($view->headline);
        self::assertSame(ExperimentMetric::MERGE_RATE, $view->headline->guards[0]->metric->key);
    }

    public function test_a_zero_baseline_point_gives_no_relative_change(): void
    {
        $this->seedHeadlineExperiment(['goal' => ['metric' => 'cost', 'better' => 'higher']], costA: '0.000000', costB: '1.000000');

        $view = $this->show();

        self::assertNotNull($view);
        self::assertNotNull($view->headline);
        self::assertSame(GoalState::Clear, $view->headline->comparisons[0]->state);
        self::assertNull($view->headline->comparisons[0]->change);
    }

    public function test_a_named_metric_sums_the_known_runs_of_each_card(): void
    {
        $definition = new ExperimentDefinition($this->project, self::EXPERIMENT, [['name' => 'a', 'weight' => 1]]);
        $definition->metrics = ['harness-events:diagnostics', 'bucket-calls:tests', 'bucket-time-all:tests', 'bucket-time:tests'];
        $this->em->persist($definition);
        $one = Uuid::v7();
        $first = $this->experimentRun($one, 'a', at: '+1 hour');
        $second = $this->experimentRun($one, 'a', at: '+2 hours');
        $first->harnessEvents = ['diagnostics' => 2];
        $second->harnessEvents = ['diagnostics' => 3];
        $two = Uuid::v7();
        $third = $this->experimentRun($two, 'a', at: '+3 hours');
        $third->harnessEvents = [];
        // A card whose runs reported nothing has no value, and no run of it adds a zero.
        $three = Uuid::v7();
        $fourth = $this->experimentRun($three, 'a', at: '+4 hours');
        foreach ([$first, $second, $third, $fourth] as $run) {
            $this->seedUsage($this->em, $run, costUsd: '1.000000');
        }
        foreach ([$one, $two, $three] as $card) {
            $this->outcomes[(string) $card] = new CardOutcomeFixture(merged: true);
        }
        $this->em->flush();
        $connection = $this->em->getConnection();
        foreach ([[$first, 4, 1000, 1500], [$second, 6, 500, 500]] as [$run, $calls, $ms, $all]) {
            $connection->insert('bridge_worker_run_bucket_times', ['id' => (string) Uuid::v7(), 'run_id' => (string) $run->id, 'bucket' => 'tests', 'ms' => $ms, 'ms_with_subagents' => $all, 'calls' => $calls]);
        }

        $view = $this->show();

        self::assertNotNull($view);
        self::assertSame(['harness-events:diagnostics', 'bucket-calls:tests', 'bucket-time-all:tests', 'bucket-time:tests'], array_keys($view->metrics));
        self::assertSame([], $view->unknownMetrics);
        self::assertEquals(Stats::bootstrapMean([5, 0], 'model-test:harness-events:diagnostics:a', Confidence::forComparisons(1)), $view->metrics['harness-events:diagnostics']->for('a'));
        self::assertEquals(Stats::bootstrapMean([10], 'model-test:bucket-calls:tests:a', Confidence::forComparisons(1)), $view->metrics['bucket-calls:tests']->for('a'));
        self::assertEquals(Stats::bootstrapMean([2000], 'model-test:bucket-time-all:tests:a', Confidence::forComparisons(1)), $view->metrics['bucket-time-all:tests']->for('a'));
        self::assertEquals(Stats::bootstrapMean([1500], 'model-test:bucket-time:tests:a', Confidence::forComparisons(1)), $view->metrics['bucket-time:tests']->for('a'));
    }

    public function test_a_named_metric_reads_only_the_experiment_runs_of_a_card(): void
    {
        $definition = new ExperimentDefinition($this->project, self::EXPERIMENT, [['name' => 'a', 'weight' => 1]]);
        $definition->metrics = ['harness-events:diagnostics', 'bucket-calls:tests'];
        $this->em->persist($definition);
        $card = Uuid::v7();
        $run = $this->experimentRun($card, 'a', at: '+1 hour');
        $run->harnessEvents = ['diagnostics' => 1];
        $this->seedUsage($this->em, $run, costUsd: '1.000000');
        $review = $this->plainRun($card, 'review', '+2 hours');
        $review->harnessEvents = ['diagnostics' => 4];
        $this->seedUsage($this->em, $review, costUsd: '1.000000');
        $this->outcomes[(string) $card] = new CardOutcomeFixture(merged: true);
        $this->em->flush();
        $connection = $this->em->getConnection();
        foreach ([[$run, 2], [$review, 7]] as [$row, $calls]) {
            $connection->insert('bridge_worker_run_bucket_times', ['id' => (string) Uuid::v7(), 'run_id' => (string) $row->id, 'bucket' => 'tests', 'ms' => 100, 'ms_with_subagents' => 100, 'calls' => $calls]);
        }

        $view = $this->show();

        self::assertNotNull($view);
        self::assertSame([], $view->cards[0]->leftOut);
        self::assertEquals(Stats::bootstrapMean([1], 'model-test:harness-events:diagnostics:a', Confidence::forComparisons(1)), $view->metrics['harness-events:diagnostics']->for('a'));
        self::assertEquals(Stats::bootstrapMean([2], 'model-test:bucket-calls:tests:a', Confidence::forComparisons(1)), $view->metrics['bucket-calls:tests']->for('a'));
    }

    public function test_a_declaration_picks_the_known_metrics_in_its_order(): void
    {
        $definition = new ExperimentDefinition($this->project, self::EXPERIMENT, [['name' => 'a', 'weight' => 1]]);
        $definition->metrics = ['duration', 'foo', 'input-tokens', 'duration', 'runs', 'bar', 'foo', 'bucket-time', 'harness-events:diagnostics', 'harness-events'];
        $this->em->persist($definition);
        $one = Uuid::v7();
        $this->seedUsage($this->em, $this->experimentRun($one, 'a', at: '+1 hour'), costUsd: '1.000000');
        // A started run with no usage makes the input tokens of its card unknown.
        $two = Uuid::v7();
        $this->seedUsage($this->em, $this->experimentRun($two, 'a', at: '+2 hours'), costUsd: '1.000000');
        $this->experimentRun($two, 'a', at: '+3 hours', state: WorkerRunState::Failed);
        foreach ([$one, $two] as $card) {
            $this->outcomes[(string) $card] = new CardOutcomeFixture(merged: true);
        }
        $this->em->flush();

        $view = $this->show();

        self::assertNotNull($view);
        self::assertSame(['duration', 'input-tokens', 'runs', 'harness-events:diagnostics'], array_keys($view->metrics));
        self::assertSame(['foo', 'bar', 'bucket-time', 'harness-events'], $view->unknownMetrics);
        self::assertEquals(new Interval(100.0, 100.0, 100.0), $view->metrics['input-tokens']->for('a'));
        self::assertEquals(Stats::bootstrapMean([300_000, 600_000], 'model-test:duration:a', Confidence::forComparisons(1)), $view->metrics['duration']->for('a'));
        self::assertEquals(Stats::bootstrapMean([1, 2], 'model-test:runs:a', Confidence::forComparisons(1)), $view->metrics['runs']->for('a'));
    }

    public function test_the_headline_reads_the_cost_that_a_declaration_leaves_out(): void
    {
        $definition = new ExperimentDefinition($this->project, self::EXPERIMENT, [['name' => 'a', 'weight' => 1], ['name' => 'b', 'weight' => 1]]);
        $definition->baseline = 'a';
        $definition->metrics = ['fix-rounds'];
        $this->em->persist($definition);
        foreach (['a' => '2.000000', 'b' => '1.000000'] as $variant => $cost) {
            for ($i = 0; $i < Stats::MIN_FINISHED_CARDS; ++$i) {
                $card = Uuid::v7();
                $this->seedUsage($this->em, $this->experimentRun($card, $variant, at: \sprintf('+%d hours', $i + 1)), costUsd: $cost);
                $this->outcomes[(string) $card] = new CardOutcomeFixture(fixRounds: ['conflict' => 1], merged: true);
            }
        }
        $this->em->flush();

        $view = $this->show();

        self::assertNotNull($view);
        self::assertSame([ExperimentMetric::FIX_ROUNDS], array_keys($view->metrics));
        self::assertSame(['conflict'], array_map(static fn (ExperimentMetric $part): string => $part->key, $view->metrics[ExperimentMetric::FIX_ROUNDS]->parts));
        self::assertSame([], $view->unknownMetrics);
        self::assertNotNull($view->headline);
        self::assertSame(GoalState::Clear, $view->headline->comparisons[0]->state);
        self::assertSame(ExperimentMetric::COST, $view->headline->goal->metric->key);
        self::assertSame([ExperimentMetric::MERGE_RATE, ExperimentMetric::FIX_ROUNDS], array_map(static fn (ExperimentGuard $guard): string => $guard->metric->key, $view->headline->guards));
    }

    public function test_an_experiment_with_no_declaration_shows_the_default_metrics(): void
    {
        $card = Uuid::v7();
        $this->seedUsage($this->em, $this->experimentRun($card, 'a'), costUsd: '1.000000');
        $this->outcomes[(string) $card] = new CardOutcomeFixture(merged: true);

        $view = $this->show();

        self::assertNotNull($view);
        self::assertSame(ShowExperimentHandler::DEFAULT_METRICS, array_keys($view->metrics));
        self::assertSame([], $view->unknownMetrics);
    }

    public function test_an_empty_declaration_shows_the_default_metrics(): void
    {
        $definition = new ExperimentDefinition($this->project, self::EXPERIMENT, [['name' => 'a', 'weight' => 1]]);
        $definition->metrics = [];
        $this->em->persist($definition);
        $card = Uuid::v7();
        $this->seedUsage($this->em, $this->experimentRun($card, 'a'), costUsd: '1.000000');
        $this->outcomes[(string) $card] = new CardOutcomeFixture(merged: true);

        $view = $this->show();

        self::assertNotNull($view);
        self::assertSame(ShowExperimentHandler::DEFAULT_METRICS, array_keys($view->metrics));
        self::assertNull($view->declaredMetrics);
        self::assertSame([], $view->unknownMetrics);
    }

    public function test_a_run_with_usage_and_no_output_tokens_keeps_its_card_out_of_the_output_token_sample(): void
    {
        $noTokens = Uuid::v7();
        $run = $this->experimentRun($noTokens, 'a', at: '+1 hour');
        $this->seedUsage($this->em, $run, costUsd: '1.000000');
        // The fact writer gives a run with usage zero tokens, so the test writes the null.
        $this->em->getConnection()->executeStatement('UPDATE bridge_worker_run_facts SET tokens_out = NULL WHERE run_id = :run', ['run' => $run->id?->toRfc4122()]);
        $withTokens = Uuid::v7();
        $tokenRun = $this->experimentRun($withTokens, 'a', at: '+2 hours');
        $this->seedUsage($this->em, $tokenRun, costUsd: '1.000000');
        $this->seedUsage($this->em, $tokenRun, model: 'claude-sonnet-5', costUsd: '2.000000');
        foreach ([$noTokens, $withTokens] as $card) {
            $this->outcomes[(string) $card] = new CardOutcomeFixture(merged: true);
        }

        $view = $this->show();

        self::assertNotNull($view);
        self::assertEquals(new Interval(40.0, 40.0, 40.0), $view->metrics[ExperimentMetric::OUTPUT_TOKENS]->for('a'));
        self::assertEquals(Stats::bootstrapMean([1.0, 3.0], 'model-test:cost:a', Confidence::forComparisons(1)), $view->metrics[ExperimentMetric::COST]->for('a'));
    }

    public function test_the_cards_tab_shows_an_unknown_cost_for_a_card_with_an_unpriced_run(): void
    {
        $unknown = Uuid::v7();
        $this->seedUsage($this->em, $this->experimentRun($unknown, 'a', at: '+1 hour'), costUsd: '1.000000');
        $this->seedUsage($this->em, $this->experimentRun($unknown, 'a', at: '+2 hours'), model: 'unpriced-model', costUsd: null);
        $known = Uuid::v7();
        $this->seedUsage($this->em, $this->experimentRun($known, 'a', at: '+3 hours'), costUsd: '2.000000');

        $view = $this->show(withMetrics: false);

        self::assertNotNull($view);
        self::assertSame([], $view->metrics);
        self::assertNull($this->card($view, $unknown)->costMicros);
        self::assertSame(2_000_000, $this->card($view, $known)->costMicros);
        self::assertSame(2_000_000, $view->variants[0]->costMicros);
    }

    public function test_too_few_finished_cards_give_no_clear_answer(): void
    {
        $this->storeBaseline('a');
        foreach (['a' => '2.000000', 'b' => '0.500000'] as $variant => $cost) {
            for ($i = 0; $i < Stats::MIN_FINISHED_CARDS - 1; ++$i) {
                $card = Uuid::v7();
                $this->seedUsage($this->em, $this->experimentRun($card, $variant, at: \sprintf('+%d hours', $i + 1)), costUsd: $cost);
                $this->outcomes[(string) $card] = new CardOutcomeFixture(merged: true);
            }
        }
        $this->em->flush();

        $view = $this->show();

        self::assertNotNull($view);
        $cost = $view->metrics[ExperimentMetric::COST];
        self::assertNotNull($cost->for('a'));
        self::assertNotNull($cost->for('b'));
        self::assertFalse($cost->clear);
        self::assertNotNull($view->headline);
        [$comparison] = $view->headline->comparisons;
        self::assertSame(GoalState::NotEnoughCards, $comparison->state);
        self::assertNull($comparison->change);
        self::assertEquals(new ExperimentArm('a', 2.0, 4), $view->headline->baselineArm);
        self::assertEquals(new ExperimentArm('b', 0.5, 4), $comparison->arm);
    }

    public function test_a_metric_judges_the_floor_on_its_own_sample(): void
    {
        $this->storeBaseline('a');
        foreach (['a' => '2.000000', 'b' => '0.500000'] as $variant => $cost) {
            for ($i = 0; $i < Stats::MIN_FINISHED_CARDS; ++$i) {
                $card = Uuid::v7();
                $run = $this->experimentRun($card, $variant, at: \sprintf('+%d hours', $i + 1));
                if (0 === $i) {
                    $this->seedUsage($this->em, $run, costUsd: $cost);
                }
                $this->outcomes[(string) $card] = new CardOutcomeFixture(merged: true, hoursToMerge: 0 === $i ? ('a' === $variant ? 2.0 : 1.0) : null);
            }
        }
        $this->em->flush();

        $view = $this->show();

        self::assertNotNull($view);
        self::assertSame([5, 5], array_map(static fn (ExperimentVariant $variant): int => $variant->finishedCards, $view->variants));
        // Five finished cards each, and one cost, one token count and one duration each.
        self::assertEquals(new Interval(2.0, 2.0, 2.0), $view->metrics[ExperimentMetric::COST]->for('a'));
        self::assertEquals(new Interval(0.5, 0.5, 0.5), $view->metrics[ExperimentMetric::COST]->for('b'));
        self::assertFalse($view->metrics[ExperimentMetric::COST]->clear);
        self::assertFalse($view->metrics[ExperimentMetric::OUTPUT_TOKENS]->clear);
        self::assertEquals(new Interval(2.0, 2.0, 2.0), $view->metrics[ExperimentMetric::HOURS_TO_MERGE]->for('a'));
        self::assertFalse($view->metrics[ExperimentMetric::HOURS_TO_MERGE]->clear);
        // Every finished card is in the fix rounds sample, so the floor holds there.
        self::assertTrue($view->metrics[ExperimentMetric::FIX_ROUNDS]->clear);
        self::assertTrue($view->metrics[ExperimentMetric::MERGE_RATE]->clear);
    }

    public function test_a_variant_with_no_usage_has_no_total_cost(): void
    {
        $this->seedUsage($this->em, $this->experimentRun(Uuid::v7(), 'a'), costUsd: '0.000000');
        $this->experimentRun(Uuid::v7(), 'b');
        $this->em->persist(new ExperimentDefinition($this->project, self::EXPERIMENT, [['name' => 'a', 'weight' => 1], ['name' => 'b', 'weight' => 1], ['name' => 'c', 'weight' => 1]]));
        $this->em->flush();

        $view = $this->show();

        self::assertNotNull($view);
        // A recorded zero stays a zero.
        self::assertSame(['a' => 0, 'b' => null, 'c' => null], array_combine(
            array_map(static fn (ExperimentVariant $variant): string => $variant->name, $view->variants),
            array_map(static fn (ExperimentVariant $variant): ?int => $variant->costMicros, $view->variants),
        ));
    }

    public function test_a_card_with_an_unpriced_usage_row_has_an_unknown_cost(): void
    {
        $unpriced = Uuid::v7();
        $run = $this->experimentRun($unpriced, 'a', at: '+1 hour');
        $this->seedUsage($this->em, $run, costUsd: '1.000000');
        $this->seedUsage($this->em, $run, model: 'unpriced-model', costUsd: null);
        $priced = Uuid::v7();
        $this->seedUsage($this->em, $this->experimentRun($priced, 'a', at: '+2 hours'), costUsd: '2.000000');
        $onlyUnpriced = Uuid::v7();
        $this->seedUsage($this->em, $this->experimentRun($onlyUnpriced, 'b', at: '+3 hours'), model: 'unpriced-model', costUsd: null);
        foreach ([$unpriced, $priced, $onlyUnpriced] as $card) {
            $this->outcomes[(string) $card] = new CardOutcomeFixture(merged: true);
        }
        $this->em->flush();

        $view = $this->show();

        self::assertNotNull($view);
        self::assertSame([(string) $onlyUnpriced => null, (string) $priced => 2_000_000, (string) $unpriced => null], array_combine(
            array_map(static fn (ExperimentCard $row): string => (string) $row->cardId, $view->cards),
            array_map(static fn (ExperimentCard $row): ?int => $row->costMicros, $view->cards),
        ));
        self::assertSame([2_000_000, null], array_map(static fn (ExperimentVariant $variant): ?int => $variant->costMicros, $view->variants));
        self::assertEquals(new Interval(2.0, 2.0, 2.0), $view->metrics[ExperimentMetric::COST]->for('a'));
        self::assertNull($view->metrics[ExperimentMetric::COST]->for('b'));
        // The tokens of an unpriced row are still known.
        self::assertSame(30.0, $view->metrics[ExperimentMetric::OUTPUT_TOKENS]->for('a')?->point);
        self::assertSame(20.0, $view->metrics[ExperimentMetric::OUTPUT_TOKENS]->for('b')?->point);
    }

    public function test_a_pin_that_names_another_variant_than_the_runs_mixes_the_card(): void
    {
        $switched = Uuid::v7();
        $this->experimentRun($switched, 'a', at: '+1 hour');
        $this->em->persist(new ExperimentPin($this->project, $switched, self::EXPERIMENT, 'b'));
        $agreed = Uuid::v7();
        $this->experimentRun($agreed, 'a', at: '+2 hours');
        $this->em->persist(new ExperimentPin($this->project, $agreed, self::EXPERIMENT, 'a'));
        $pinOnly = Uuid::v7();
        $this->em->persist(new ExperimentPin($this->project, $pinOnly, self::EXPERIMENT, 'b'));
        $this->em->flush();

        $view = $this->show();

        self::assertNotNull($view);
        self::assertSame([LeftOutReason::Mixed], $this->card($view, $switched)->leftOut);
        self::assertSame('a', $this->card($view, $switched)->variant);
        self::assertSame([], $this->card($view, $agreed)->leftOut);
        self::assertSame('a', $this->card($view, $agreed)->variant);
        self::assertSame('b', $this->card($view, $pinOnly)->variant);
    }

    /**
     * @param array<string, array{cost: string, cards: int}>                             $arms     variant => the cost of each of its merged cards, and how many there are
     * @param list<array{metric: string, variant: string, expect: string, share: float}> $controls
     */
    private function seedArms(?string $baseline, array $arms, array $controls = []): void
    {
        $definition = new ExperimentDefinition($this->project, self::EXPERIMENT, array_map(static fn (string $name): array => ['name' => $name, 'weight' => 1], array_keys($arms)));
        $definition->baseline = $baseline;
        $definition->headline = ['goal' => ['metric' => 'cost', 'better' => 'lower'], 'controls' => $controls];
        $this->em->persist($definition);
        $hour = 0;
        foreach ($arms as $variant => $arm) {
            for ($i = 0; $i < $arm['cards']; ++$i) {
                $card = Uuid::v7();
                $this->seedUsage($this->em, $this->experimentRun($card, $variant, at: \sprintf('+%d hours', ++$hour)), costUsd: $arm['cost']);
                $this->outcomes[(string) $card] = new CardOutcomeFixture(merged: true);
            }
        }
        $this->em->flush();
    }

    public function test_three_arms_are_compared_against_the_baseline_at_a_wider_level(): void
    {
        $this->seedArms('zeta', ['zeta' => ['cost' => '2.000000', 'cards' => 5], 'alpha' => ['cost' => '1.000000', 'cards' => 5], 'beta' => ['cost' => '2.000000', 'cards' => 5]]);

        $view = $this->show();

        self::assertNotNull($view);
        self::assertSame(['zeta', 'alpha', 'beta'], array_map(static fn (ExperimentVariant $variant): string => $variant->name, $view->variants));
        self::assertNotNull($view->headline);
        self::assertSame('zeta', $view->headline->baseline);
        self::assertSame(0.975, $view->headline->confidence->level);
        self::assertSame(['alpha', 'beta'], array_map(static fn (ExperimentComparison $comparison): string => $comparison->variant, $view->headline->comparisons));
        [$alpha, $beta] = $view->headline->comparisons;
        self::assertSame(GoalState::Clear, $alpha->state);
        self::assertEqualsWithDelta(-0.5, $alpha->change?->point, 1e-12);
        self::assertSame(GoalState::NotClear, $beta->state);
        self::assertEquals(Stats::wilson(5, 5, Confidence::forComparisons(2)), $view->metrics[ExperimentMetric::MERGE_RATE]->for('alpha'));
        self::assertNotEquals(Stats::wilson(5, 5, Confidence::forComparisons(1)), $view->metrics[ExperimentMetric::MERGE_RATE]->for('alpha'));
        self::assertTrue($view->metrics[ExperimentMetric::COST]->clear);
    }

    public function test_a_variant_with_no_cards_shows_no_change_and_makes_every_metric_unclear(): void
    {
        $this->seedArms('base', ['base' => ['cost' => '2.000000', 'cards' => 5], 'treated' => ['cost' => '1.000000', 'cards' => 5], 'empty' => ['cost' => '1.000000', 'cards' => 0]]);

        $view = $this->show();

        self::assertNotNull($view);
        self::assertNotNull($view->headline);
        [$empty, $treated] = $view->headline->comparisons;
        self::assertSame('empty', $empty->variant);
        self::assertSame(GoalState::NotEnoughCards, $empty->state);
        self::assertEquals(new ExperimentArm('empty', null, 0), $empty->arm);
        self::assertNull($empty->change);
        self::assertSame(GoalState::Clear, $treated->state);
        self::assertFalse($view->metrics[ExperimentMetric::COST]->clear);
    }

    public function test_a_failed_control_on_a_treated_arm_holds_only_that_variant(): void
    {
        $this->seedArms('base', ['base' => ['cost' => '2.000000', 'cards' => 5], 'alpha' => ['cost' => '1.000000', 'cards' => 5], 'beta' => ['cost' => '1.000000', 'cards' => 5]], [
            ['metric' => 'cost', 'variant' => 'alpha', 'expect' => '> 100', 'share' => 1.0],
            ['metric' => 'cost', 'variant' => 'base', 'expect' => '> 0', 'share' => 1.0],
        ]);

        $view = $this->show();

        self::assertNotNull($view);
        self::assertNotNull($view->headline);
        [$alpha, $beta] = $view->headline->comparisons;
        self::assertSame(GoalState::Held, $alpha->state);
        self::assertSame(ControlResult::Failed, $alpha->hold?->reason);
        self::assertNull($alpha->change);
        self::assertSame(GoalState::Clear, $beta->state);
        self::assertNull($beta->hold);
    }

    public function test_a_failed_control_on_the_baseline_holds_every_variant(): void
    {
        $this->seedArms('base', ['base' => ['cost' => '2.000000', 'cards' => 5], 'alpha' => ['cost' => '1.000000', 'cards' => 5], 'beta' => ['cost' => '1.000000', 'cards' => 5]], [
            ['metric' => 'cost', 'variant' => 'base', 'expect' => '> 100', 'share' => 1.0],
        ]);

        $view = $this->show();

        self::assertNotNull($view);
        self::assertNotNull($view->headline);
        self::assertSame([GoalState::Held, GoalState::Held], array_map(static fn (ExperimentComparison $comparison): GoalState => $comparison->state, $view->headline->comparisons));
    }

    public function test_a_variant_that_is_the_baseline_by_name_alone_still_comes_first(): void
    {
        $this->seedArms('b', ['a' => ['cost' => '1.000000', 'cards' => 5], 'b' => ['cost' => '2.000000', 'cards' => 5]]);

        $view = $this->show();

        self::assertNotNull($view);
        self::assertSame(['b', 'a'], array_map(static fn (ExperimentVariant $variant): string => $variant->name, $view->variants));
        self::assertNotNull($view->headline);
        self::assertSame(0.95, $view->headline->confidence->level);
    }

    public function test_a_single_variant_with_no_baseline_makes_no_comparison(): void
    {
        for ($i = 0; $i < 6; ++$i) {
            $card = Uuid::v7();
            $this->seedUsage($this->em, $this->experimentRun($card, 'a', at: \sprintf('+%d hours', $i + 1)), costUsd: '1.000000');
            $this->outcomes[(string) $card] = new CardOutcomeFixture(merged: true);
        }
        $this->em->flush();

        $view = $this->show();

        self::assertNotNull($view);
        self::assertSame(1.0, $view->metrics[ExperimentMetric::MERGE_RATE]->for('a')?->point);
        foreach ($view->metrics as $metric) {
            self::assertNull($metric->clear, $metric->key);
        }
        self::assertNotNull($view->headline);
        self::assertNull($view->headline->baseline);
        self::assertNull($view->headline->baselineArm);
        self::assertSame([], $view->headline->comparisons);
        self::assertSame(0.95, $view->headline->confidence->level);
        foreach ($view->headline->guards as $guard) {
            self::assertNull($guard->baselinePoint);
        }
    }

    public function test_the_cards_tab_pages_and_filters_the_cards(): void
    {
        $ids = [];
        for ($i = 0; $i < 22; ++$i) {
            $ids[] = $card = Uuid::v7();
            $this->experimentRun($card, 'a', at: \sprintf('+%d hours', $i + 1));
        }
        $this->experimentRun($b = Uuid::v7(), 'b', at: '+100 hours');
        $this->experimentRun($leftOut = Uuid::v7(), 'b', at: '+101 hours', switchedFrom: 'a');

        $first = $this->show();
        $second = $this->show(page: 2);

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame(24, $first->filteredTotal);
        self::assertSame(2, $first->totalPages);
        self::assertSame([(string) $leftOut, (string) $b], array_map(static fn (ExperimentCard $card): string => (string) $card->cardId, \array_slice($first->cards, 0, 2)));
        self::assertCount(20, $first->cards);
        self::assertSame([(string) $ids[1], (string) $ids[0]], array_map(static fn (ExperimentCard $card): string => (string) $card->cardId, \array_slice($second->cards, -2)));
        self::assertCount(4, $second->cards);
        self::assertSame(2, $this->show(page: 9)?->clampedPage);

        $onlyB = $this->show(variant: 'b');
        $onlyLeftOut = $this->show(leftOutOnly: true);
        self::assertNotNull($onlyB);
        self::assertNotNull($onlyLeftOut);
        self::assertSame([(string) $b], array_map(static fn (ExperimentCard $card): string => (string) $card->cardId, $onlyB->cards));
        self::assertSame([(string) $leftOut], array_map(static fn (ExperimentCard $card): string => (string) $card->cardId, $onlyLeftOut->cards));
    }

    public function test_the_per_card_sample_keeps_the_cards_that_ended_with_the_delivered_outcome(): void
    {
        $this->templateSet = new TemplateMetricSet(
            [new TemplateMetric('hours-to-ship', 'Hours to ship', 'Hours to ship.', MetricType::Duration, from: new DurationEvent(DurationEvent::PR_OPENED), to: new DurationEvent(DurationEvent::PR_MERGED))],
            [new TemplateOutcome('shipped', true), new TemplateOutcome('merged')],
        );
        $this->outcomeKey = 'shipped';
        $definition = new ExperimentDefinition($this->project, self::EXPERIMENT, [['name' => 'a', 'weight' => 1]]);
        $definition->metrics = ['cost', 'hours-to-ship'];
        $this->em->persist($definition);
        $shipped = Uuid::v7();
        $this->seedUsage($this->em, $this->experimentRun($shipped, 'a', at: '+1 hour'), costUsd: '3.000000');
        $this->outcomes[(string) $shipped] = new CardOutcomeFixture(merged: true, hoursToMerge: 5.0);
        $open = Uuid::v7();
        $this->seedUsage($this->em, $this->experimentRun($open, 'a', at: '+2 hours'), costUsd: '9.000000');
        $this->outcomes[(string) $open] = new CardOutcomeFixture(hoursToMerge: 7.0);

        $view = $this->show();

        self::assertNotNull($view);
        self::assertEquals(new Interval(3.0, 3.0, 3.0), $view->metrics[ExperimentMetric::COST]->for('a'));
        self::assertEquals(new Interval(5.0, 5.0, 5.0), $view->metrics['hours-to-ship']->for('a'));
        self::assertSame(1, $view->variants[0]->finishedCards);
    }

    public function test_a_template_with_no_delivered_outcome_has_no_cards_in_the_per_card_sample(): void
    {
        $this->templateSet = new TemplateMetricSet(
            [new TemplateMetric('hours-to-merge', 'Hours to merge', 'Hours to merge.', MetricType::Duration, from: new DurationEvent(DurationEvent::PR_OPENED), to: new DurationEvent(DurationEvent::PR_MERGED))],
            [new TemplateOutcome('merged')],
        );
        $card = Uuid::v7();
        $this->seedUsage($this->em, $this->experimentRun($card, 'a'), costUsd: '1.000000');
        $this->outcomes[(string) $card] = new CardOutcomeFixture(merged: true, hoursToMerge: 2.0);

        $view = $this->show();

        self::assertNotNull($view);
        self::assertNull($view->metrics[ExperimentMetric::COST]->for('a'));
        self::assertNull($view->metrics['hours-to-merge']->for('a'));
        self::assertSame(0, $view->variants[0]->finishedCards);
    }

    public function test_a_default_metric_that_the_template_does_not_declare_drops_out_without_a_note(): void
    {
        $this->templateSet = new TemplateMetricSet(
            [new TemplateMetric('merge-rate', 'Merge rate', 'The share of merged cards.', MetricType::Rate, outcome: 'merged')],
            [new TemplateOutcome('merged', true)],
        );
        $card = Uuid::v7();
        $this->seedUsage($this->em, $this->experimentRun($card, 'a'), costUsd: '1.000000');
        $this->outcomes[(string) $card] = new CardOutcomeFixture(merged: true);

        $view = $this->show();

        self::assertNotNull($view);
        self::assertSame(['merge-rate', 'stop-rate', 'cost', 'covered-cost', 'output-tokens'], array_keys($view->metrics));
        self::assertSame([], $view->unknownMetrics);
        self::assertSame('Merge rate', $view->metrics['merge-rate']->label);
        self::assertNull($view->metrics['cost']->label);
    }

    public function test_a_declared_key_that_the_template_does_not_declare_is_unknown(): void
    {
        $this->templateSet = new TemplateMetricSet([], [new TemplateOutcome('merged', true)]);
        $definition = new ExperimentDefinition($this->project, self::EXPERIMENT, [['name' => 'a', 'weight' => 1]]);
        $definition->metrics = ['fix-rounds', 'cost'];
        $this->em->persist($definition);
        $card = Uuid::v7();
        $this->seedUsage($this->em, $this->experimentRun($card, 'a'), costUsd: '1.000000');
        $this->outcomes[(string) $card] = new CardOutcomeFixture(merged: true);

        $view = $this->show();

        self::assertNotNull($view);
        self::assertSame(['cost'], array_keys($view->metrics));
        self::assertSame(['fix-rounds'], $view->unknownMetrics);
        self::assertNotNull($view->headline);
        self::assertSame([], $view->headline->guards);
        self::assertSame([], $view->unknownGuards);
    }

    public function test_a_count_that_splits_by_reason_gets_one_row_per_reason(): void
    {
        $this->templateSet = new TemplateMetricSet(
            [new TemplateMetric('design-rounds', 'Design rounds', 'Rounds of design review.', MetricType::Count, new CountFilter(role: 'design-revise'), 'reason')],
            [new TemplateOutcome('merged', true)],
        );
        $definition = new ExperimentDefinition($this->project, self::EXPERIMENT, [['name' => 'a', 'weight' => 1]]);
        $definition->metrics = ['design-rounds'];
        $this->em->persist($definition);
        $project = $this->managedProject();
        $perCard = [['conflict' => 2], ['conflict' => 1, 'checks-failed' => 1]];
        foreach ($perCard as $i => $reasons) {
            $card = Uuid::v7();
            $this->experimentRun($card, 'a', at: \sprintf('+%d hours', $i + 1));
            $this->outcomes[(string) $card] = new CardOutcomeFixture(merged: true);
            foreach ($reasons as $reason => $rounds) {
                for ($n = 0; $n < $rounds; ++$n) {
                    $request = $this->seedWorkRequest($this->em, $project, $card, 'design', state: WorkRequestState::Done);
                    $request->role = 'design-revise';
                    $request->context = new WorkRequestContext(reason: $reason);
                }
            }
            // A fix request is no design round.
            $other = $this->seedWorkRequest($this->em, $project, $card, 'fix', state: WorkRequestState::Done);
            $other->role = 'fix';
            $other->context = new WorkRequestContext(reason: 'agent-review');
        }
        $this->em->flush();

        $view = $this->show();

        self::assertNotNull($view);
        $rounds = $view->metrics['design-rounds'];
        self::assertSame('Design rounds', $rounds->label);
        self::assertSame(MetricValueType::Count, $rounds->valueType());
        self::assertEquals(Stats::bootstrapMean([2, 2], 'model-test:design-rounds:a', Confidence::forComparisons(1)), $rounds->for('a'));
        self::assertSame(['checks-failed', 'conflict'], array_map(static fn (ExperimentMetric $part): string => $part->key, $rounds->parts));
        self::assertNull($rounds->parts[0]->label);
        self::assertNull($rounds->parts[0]->shape);
        self::assertEquals(Stats::bootstrapMean([0, 1], 'model-test:design-rounds:checks-failed:a', Confidence::forComparisons(1)), $rounds->parts[0]->for('a'));
        self::assertEquals(Stats::bootstrapMean([2, 1], 'model-test:design-rounds:conflict:a', Confidence::forComparisons(1)), $rounds->parts[1]->for('a'));
    }

    public function test_a_count_that_splits_by_role_labels_its_rows_with_the_role(): void
    {
        $this->templateSet = new TemplateMetricSet(
            [new TemplateMetric('requests', 'Requests', 'Every fix or design request.', MetricType::Count, new CountFilter(kind: 'fix'), 'role')],
            [new TemplateOutcome('merged', true)],
        );
        $definition = new ExperimentDefinition($this->project, self::EXPERIMENT, [['name' => 'a', 'weight' => 1]]);
        $definition->metrics = ['requests'];
        $this->em->persist($definition);
        $card = Uuid::v7();
        $this->experimentRun($card, 'a');
        $this->outcomes[(string) $card] = new CardOutcomeFixture(fixRounds: ['conflict' => 2], merged: true);

        $view = $this->show();

        self::assertNotNull($view);
        $parts = $view->metrics['requests']->parts;
        self::assertSame(['fix'], array_map(static fn (ExperimentMetric $part): string => $part->key, $parts));
        self::assertSame('fix', $parts[0]->label);
        self::assertSame(2.0, $parts[0]->for('a')?->point);
    }

    public function test_cost_and_runs_count_every_worker_run_and_the_covered_cost_the_covered_runs(): void
    {
        $definition = new ExperimentDefinition($this->project, self::EXPERIMENT, [['name' => 'a', 'weight' => 1]]);
        $definition->metrics = ['runs', ExperimentMetric::COST, ExperimentMetric::COVERED_COST];
        $this->em->persist($definition);
        $card = Uuid::v7();
        $this->seedUsage($this->em, $this->experimentRun($card, 'a', at: '+1 hour'), costUsd: '1.000000');
        $this->seedUsage($this->em, $this->experimentRun($card, 'a', at: '+2 hours', workKind: 'fix'), costUsd: '2.000000');
        $this->seedUsage($this->em, $this->plainRun($card, 'review', '+3 hours'), costUsd: '4.000000');
        $this->outcomes[(string) $card] = new CardOutcomeFixture(merged: true);

        $view = $this->show();

        self::assertNotNull($view);
        self::assertSame([], $view->cards[0]->leftOut);
        self::assertSame([3, 7_000_000, 3_000_000], [$view->cards[0]->runs, $view->cards[0]->costMicros, $view->cards[0]->coveredCostMicros]);
        self::assertEquals([new ExperimentVariant('a', ['claude-opus-5-5'], 1, 1, 1, 3, 7_000_000, 3_000_000)], $view->variants);
        self::assertSame(3.0, $view->metrics['runs']->for('a')?->point);
        self::assertSame(7.0, $view->metrics[ExperimentMetric::COST]->for('a')?->point);
        self::assertSame(3.0, $view->metrics[ExperimentMetric::COVERED_COST]->for('a')?->point);
        self::assertSame(MetricValueType::Money, $view->metrics[ExperimentMetric::COVERED_COST]->valueType());
    }

    public function test_a_stored_covered_list_decides_which_runs_are_covered(): void
    {
        $definition = new ExperimentDefinition($this->project, self::EXPERIMENT, [['name' => 'a', 'weight' => 1]]);
        $definition->covers = ['implement'];
        $this->em->persist($definition);
        $card = Uuid::v7();
        $this->seedUsage($this->em, $this->experimentRun($card, 'a', at: '+1 hour'), costUsd: '1.000000');
        $this->seedUsage($this->em, $this->experimentRun($card, 'a', at: '+2 hours', workKind: 'fix'), costUsd: '2.000000');
        $this->outcomes[(string) $card] = new CardOutcomeFixture(merged: true);

        $view = $this->show();

        self::assertNotNull($view);
        self::assertSame([3_000_000, 1_000_000], [$view->cards[0]->costMicros, $view->cards[0]->coveredCostMicros]);
    }

    public function test_a_covered_kind_without_a_run_leaves_a_plain_run_of_it_out(): void
    {
        $definition = new ExperimentDefinition($this->project, self::EXPERIMENT, [['name' => 'a', 'weight' => 1]]);
        $definition->covers = ['fix', 'implement'];
        $this->em->persist($definition);
        $card = Uuid::v7();
        $this->experimentRun($card, 'a', at: '+1 hour');
        $this->plainRun($card, 'fix', '+2 hours');

        $view = $this->show();

        self::assertNotNull($view);
        self::assertSame([LeftOutReason::CoveredWithoutVariant], $view->cards[0]->leftOut);
    }

    public function test_the_stop_rate_counts_the_covered_runs_that_ran_on_the_variant(): void
    {
        $card = Uuid::v7();
        $this->experimentRun($card, 'a', at: '+1 hour');
        $this->experimentRun($card, 'a', at: '+2 hours', state: WorkerRunState::Failed, workKind: 'fix');
        $this->outcomes[(string) $card] = new CardOutcomeFixture(merged: true);

        $withBoth = $this->show();

        $definition = new ExperimentDefinition($this->managedProject(), self::EXPERIMENT, [['name' => 'a', 'weight' => 1]]);
        $definition->covers = ['implement'];
        $this->em->persist($definition);
        $this->em->flush();
        $withOne = $this->show();

        self::assertNotNull($withBoth);
        self::assertNotNull($withOne);
        self::assertEquals(Stats::wilson(1, 2, Confidence::forComparisons(1)), $withBoth->metrics[ExperimentMetric::STOP_RATE]->for('a'));
        self::assertEquals(Stats::wilson(0, 1, Confidence::forComparisons(1)), $withOne->metrics[ExperimentMetric::STOP_RATE]->for('a'));
    }

    public function test_a_variant_lists_every_model_that_its_runs_asked_for_in_order(): void
    {
        $card = Uuid::v7();
        $this->experimentRun($card, 'a', at: '+1 hour', model: 'claude-sonnet-5-5');
        $this->experimentRun($card, 'a', at: '+2 hours', model: 'claude-opus-5-5');
        $this->experimentRun($card, 'a', at: '+3 hours', model: 'claude-sonnet-5-5');
        $this->experimentRun($card, 'a', at: '+4 hours', model: null);
        $unnamed = Uuid::v7();
        $this->experimentRun($unnamed, 'b', model: null);

        $view = $this->show();

        self::assertNotNull($view);
        self::assertSame(['claude-opus-5-5', 'claude-sonnet-5-5'], $view->variants[0]->models);
        self::assertSame([], $view->variants[1]->models);
    }

    public function test_a_plain_covered_run_whose_row_was_swept_still_leaves_the_card_out(): void
    {
        $after = Uuid::v7();
        $this->seedUsage($this->em, $this->experimentRun($after, 'a', at: '+1 hour'), costUsd: '1.000000');
        $this->seedUsage($this->em, $this->plainRun($after, 'implement', '+2 hours'), costUsd: '1.000000');
        $before = Uuid::v7();
        $this->seedUsage($this->em, $this->plainRun($before, 'implement', '+1 hour'), costUsd: '1.000000');
        $this->seedUsage($this->em, $this->experimentRun($before, 'a', at: '+2 hours'), costUsd: '1.000000');
        $this->sweepRows($after);
        $this->sweepRows($before);

        $view = $this->show();

        self::assertNotNull($view);
        self::assertSame([(string) $after => ['covered-without-variant'], (string) $before => ['before-test']], $this->reasonsById($view, [$after, $before]));
        self::assertSame(2, $this->card($view, $after)->runs);
    }

    public function test_a_card_whose_pin_and_run_rows_expired_keeps_the_variant_of_its_oldest_experiment_fact(): void
    {
        $card = Uuid::v7();
        $this->seedUsage($this->em, $this->experimentRun($card, 'a', at: '+1 hour'), costUsd: '1.000000');
        $this->seedUsage($this->em, $this->experimentRun($card, 'a', at: '+2 hours', workKind: 'fix'), costUsd: '2.000000');
        $this->seedUsage($this->em, $this->plainRun($card, 'review', '+3 hours'), costUsd: '4.000000');
        $this->sweepRows($card);

        $view = $this->show();

        self::assertNotNull($view);
        $row = $this->card($view, $card);
        self::assertSame(['a', [], 1, 3, 7_000_000, 3_000_000], [$row->variant, $row->leftOut, $row->number, $row->runs, $row->costMicros, $row->coveredCostMicros]);
        self::assertEquals([new ExperimentVariant('a', [], null, 1, 0, 3, 7_000_000, 3_000_000)], $view->variants);
    }

    public function test_a_plain_covered_run_with_no_fact_leaves_out_a_card_that_its_facts_keep(): void
    {
        $card = Uuid::v7();
        $this->seedUsage($this->em, $this->experimentRun($card, 'a', at: '+1 hour'), costUsd: '1.000000');
        $this->sweepRows($card);
        $running = $this->plainRun($card, 'implement', '+2 hours', WorkerRunState::Running);
        // A fact write that fails after the commit leaves the row with no fact.
        $this->em->getConnection()->executeStatement('DELETE FROM bridge_worker_run_facts WHERE run_id = :run', ['run' => (string) $running->id]);

        $view = $this->show();

        self::assertNotNull($view);
        self::assertSame([(string) $card => ['covered-without-variant']], $this->reasonsById($view, [$card]));
    }

    private function sweepRows(Uuid $card): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM bridge_worker_runs WHERE subject_id = :card', ['card' => (string) $card]);
    }

    private function storeBaseline(string $baseline): void
    {
        $definition = new ExperimentDefinition($this->project, self::EXPERIMENT, []);
        $definition->baseline = $baseline;
        $this->em->persist($definition);
    }

    private function show(int $page = 1, ?string $variant = null, bool $leftOutOnly = false, bool $withMetrics = true): ?ExperimentReportView
    {
        $this->storeOutcomes();
        $this->em->clear();
        $reports = new readonly class($this->columns, $this->historyStart, array_map(fn (CardOutcomeFixture $outcome): array => $outcome->merged ? [$this->outcomeKey] : [], $this->outcomes)) implements CardReportSourceInterface {
            /**
             * @param array<string, CardColumn>   $columns
             * @param array<string, list<string>> $outcomes
             */
            public function __construct(
                private array $columns,
                private ?\DateTimeImmutable $historyStart,
                private array $outcomes,
            ) {
            }

            #[\Override]
            public function columnsFor(Project $project, array $cardIds): array
            {
                return $this->columns;
            }

            #[\Override]
            public function outcomesOfCards(Project $project, array $cardIds): array
            {
                return array_filter($this->outcomes, static fn (array $keys): bool => [] !== $keys);
            }

            #[\Override]
            public function typesFor(Project $project, array $cardIds): array
            {
                return [];
            }

            #[\Override]
            public function historyStartFor(Project $project): ?\DateTimeImmutable
            {
                return $this->historyStart;
            }
        };
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
        $templates->method('forProject')->willReturn($this->templateSet);
        $titles = new readonly class implements CardTitleSourceInterface {
            #[\Override]
            public function titlesFor(Project $project, array $cardIds): array
            {
                return [];
            }
        };
        $container = self::getContainer();
        $handler = new ShowExperimentHandler(
            $container->get(WorkerRunRepository::class),
            $container->get(ExperimentPinRepository::class),
            $container->get(ExperimentDefinitionRepository::class),
            $container->get(WorkerRunFactRepository::class),
            $reports,
            $titles,
            new TemplateMetricValues($container->get(WorkRequestRepository::class), $reports, $templates, [$milestones]),
            $templates,
            $container->get(MetricRowSource::class),
            $container->get(WorkerRunBucketTimeRepository::class),
            $this->bucketRuleSources,
        );

        return $handler(new ShowExperimentCommand($this->managedProject(), self::EXPERIMENT, $page, $variant, $leftOutOnly, $withMetrics));
    }

    /** Writes the fix rounds and the pull request times of the declared outcomes where the handler reads them. */
    private function storeOutcomes(): void
    {
        foreach ($this->outcomes as $cardId => $outcome) {
            if (isset($this->stored[$cardId])) {
                continue;
            }
            $this->stored[$cardId] = true;
            $project = $this->managedProject();
            foreach ($outcome->fixRounds as $reason => $rounds) {
                for ($i = 0; $i < $rounds; ++$i) {
                    $request = $this->seedWorkRequest($this->em, $project, Uuid::fromString($cardId), 'fix', state: WorkRequestState::Done);
                    $request->role = 'fix';
                    $request->context = new WorkRequestContext(reason: $reason);
                }
            }
            if (null !== $outcome->hoursToMerge) {
                $opened = new \DateTimeImmutable('2026-09-02 08:00:00');
                $this->milestones[$cardId] = [
                    new CardMilestone(DurationEvent::PR_OPENED, $opened),
                    new CardMilestone(DurationEvent::PR_MERGED, $opened->modify(\sprintf('+%d seconds', (int) ($outcome->hoursToMerge * 3600)))),
                ];
            }
        }
        $this->em->flush();
    }

    private function managedProject(): Project
    {
        return $this->em->find(Project::class, $this->project->id) ?? throw new \LogicException('The project exists.');
    }

    public function test_a_started_run_with_no_usage_makes_its_card_cost_unknown(): void
    {
        $partial = Uuid::v7();
        $this->seedUsage($this->em, $this->experimentRun($partial, 'a', at: '+1 hour'), costUsd: '1.000000');
        $this->experimentRun($partial, 'a', at: '+2 hours', state: WorkerRunState::Failed);
        $queued = Uuid::v7();
        $this->seedUsage($this->em, $this->experimentRun($queued, 'a', at: '+3 hours'), costUsd: '2.000000');
        $this->experimentRun($queued, 'a', at: '+4 hours', state: WorkerRunState::Queued)->startedAt = null;
        $this->experimentRun($queued, 'a', at: '+5 hours', state: WorkerRunState::Stopped)->startedAt = null;
        $this->em->flush();
        foreach ([$partial, $queued] as $card) {
            $this->outcomes[(string) $card] = new CardOutcomeFixture(merged: true);
        }

        $view = $this->show();

        self::assertNotNull($view);
        self::assertSame([(string) $queued => 2_000_000, (string) $partial => null], array_combine(
            array_map(static fn (ExperimentCard $row): string => (string) $row->cardId, $view->cards),
            array_map(static fn (ExperimentCard $row): ?int => $row->costMicros, $view->cards),
        ));
        self::assertEquals(new Interval(2.0, 2.0, 2.0), $view->metrics[ExperimentMetric::COST]->for('a'));
        self::assertEquals(new Interval(20.0, 20.0, 20.0), $view->metrics[ExperimentMetric::OUTPUT_TOKENS]->for('a'));
    }

    private function experimentRun(
        Uuid $cardId,
        string $variant,
        string $at = '+1 hour',
        WorkerRunState $state = WorkerRunState::Succeeded,
        ?string $switchedFrom = null,
        ?string $model = 'claude-opus-5-5',
        string $experiment = self::EXPERIMENT,
        string $workKind = 'implement',
    ): WorkerRun {
        // A run with no key reads as one from an older bridge, and a trigger rewrites its failed state.
        $run = $this->seedRun($this->em, $this->managedProject(), receivedAt: $this->start->modify($at), workKind: $workKind, cardId: $cardId, state: $state, runKey: Uuid::v7());
        $run->experiment = $experiment;
        $run->variant = $variant;
        $run->switchedFrom = $switchedFrom;
        $run->requestedModel = $model;
        $this->em->flush();

        return $run;
    }

    private function plainRun(Uuid $cardId, string $workKind, string $at, WorkerRunState $state = WorkerRunState::Succeeded): WorkerRun
    {
        return $this->seedRun($this->em, $this->managedProject(), receivedAt: $this->start->modify($at), workKind: $workKind, cardId: $cardId, state: $state);
    }

    /**
     * @param list<Uuid> $ids
     *
     * @return array<string, list<string>>
     */
    private function reasonsById(ExperimentReportView $view, array $ids): array
    {
        $reasons = [];
        foreach ($ids as $id) {
            $reasons[(string) $id] = array_map(static fn (LeftOutReason $reason): string => $reason->value, $this->card($view, $id)->leftOut);
        }

        return $reasons;
    }

    private function card(ExperimentReportView $view, Uuid $id): ExperimentCard
    {
        foreach ($view->cards as $card) {
            if ($card->cardId->equals($id)) {
                return $card;
            }
        }

        throw new \LogicException('The card is on the page.');
    }
}
