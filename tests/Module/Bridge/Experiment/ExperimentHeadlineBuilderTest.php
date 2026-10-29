<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Experiment;

use App\Module\Bridge\Experiment\Confidence;
use App\Module\Bridge\Experiment\ControlEvaluator;
use App\Module\Bridge\Experiment\ControlResult;
use App\Module\Bridge\Experiment\ExperimentArm;
use App\Module\Bridge\Experiment\ExperimentControl;
use App\Module\Bridge\Experiment\ExperimentHeadline;
use App\Module\Bridge\Experiment\ExperimentHeadlineBuilder;
use App\Module\Bridge\Experiment\ExperimentMetric;
use App\Module\Bridge\Experiment\GoalDirection;
use App\Module\Bridge\Experiment\GoalState;
use App\Module\Bridge\Experiment\Interval;
use App\Module\Bridge\Experiment\Stats;
use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricKey;
use App\Module\Bridge\Metric\MetricShape;
use PHPUnit\Framework\TestCase;

final class ExperimentHeadlineBuilderTest extends TestCase
{
    /** @param array<string, list<int|float>> $samples */
    private static function metric(array $samples, Metric $of = Metric::Cost): ExperimentMetric
    {
        $byVariant = array_map(static fn (array $values): ?Interval => [] === $values ? null : new Interval((float) min($values), (float) max($values), array_sum($values) / \count($values)), $samples);

        return new ExperimentMetric($of->value, $byVariant, null, [], new MetricShape(new MetricKey($of)), null, $samples);
    }

    /** @return array<string, list<float>> three arms: the baseline at 1.0, one half as dear, one the same */
    private static function threeArms(): array
    {
        return ['base' => [1.0, 1.0, 1.0, 1.0, 1.0], 'cheap' => [0.5, 0.5, 0.5, 0.5, 0.5], 'same' => [1.0, 1.0, 1.0, 1.0, 1.0]];
    }

    /**
     * @param array<string, int>      $finished
     * @param list<ExperimentControl> $controls
     * @param list<ExperimentMetric>  $guards
     */
    private static function build(ExperimentMetric $goal, array $finished, ?string $baseline = 'base', array $controls = [], ?GoalState $problem = null, ?Confidence $confidence = null, array $guards = [], ?GoalDirection $direction = GoalDirection::Lower): ExperimentHeadline
    {
        return ExperimentHeadlineBuilder::build('test', array_keys($goal->byVariant), $finished, $goal, $direction, $guards, $baseline, $confidence ?? Confidence::forComparisons(2), $problem, $controls);
    }

    public function test_each_variant_is_read_against_the_baseline(): void
    {
        $metric = self::metric(self::threeArms());

        $headline = self::build($metric, ['base' => 5, 'cheap' => 5, 'same' => 5]);

        self::assertSame('base', $headline->baseline);
        self::assertEquals(new ExperimentArm('base', 1.0, 5), $headline->baselineArm);
        self::assertSame(['cheap', 'same'], array_map(static fn ($comparison): string => $comparison->variant, $headline->comparisons));
        [$cheap, $same] = $headline->comparisons;
        self::assertSame(GoalState::Clear, $cheap->state);
        self::assertEquals(new ExperimentArm('cheap', 0.5, 5), $cheap->arm);
        self::assertTrue($cheap->relative);
        self::assertNotNull($cheap->change);
        self::assertEqualsWithDelta(-0.5, $cheap->change->point, 1e-12);
        self::assertSame(GoalState::NotClear, $same->state);
        self::assertNotNull($same->change);
        self::assertEqualsWithDelta(0.0, $same->change->point, 1e-12);
    }

    public function test_the_change_is_read_at_the_given_level_with_a_seed_that_names_the_variant(): void
    {
        $samples = ['base' => [1.0, 2.0, 3.0, 2.0, 4.0], 'a' => [1.0, 1.5, 2.0, 0.5, 1.0], 'b' => [2.0, 2.5, 3.0, 1.5, 2.0]];
        $metric = self::metric($samples);
        $level = Confidence::forComparisons(2);

        $headline = self::build($metric, ['base' => 5, 'a' => 5, 'b' => 5], confidence: $level);

        self::assertSame($level, $headline->confidence);
        self::assertEquals(Stats::changeRange($samples['base'], $samples['a'], 'test:cost:change:a', $level), $headline->comparisons[0]->change);
        self::assertEquals(Stats::changeRange($samples['base'], $samples['b'], 'test:cost:change:b', $level), $headline->comparisons[1]->change);
        self::assertNotEquals(Stats::changeRange($samples['base'], $samples['a'], 'test:cost:change:a', Confidence::forComparisons(1)), $headline->comparisons[0]->change);
    }

    public function test_a_ratio_goal_reads_the_difference_of_two_ratios(): void
    {
        $metric = self::metric(['base' => [0, 0, 1, 0, 0, 0], 'a' => [1, 1, 1, 0, 1, 1]], Metric::StopRate);
        $metric = new ExperimentMetric('merge-rate', $metric->byVariant, null, [], new MetricShape(new MetricKey(Metric::StopRate)), null, $metric->samples);

        $headline = self::build($metric, ['base' => 6, 'a' => 6], direction: GoalDirection::Higher);

        $comparison = $headline->comparisons[0];
        self::assertFalse($comparison->relative);
        self::assertEquals(Stats::newcombe(1, 6, 5, 6, Confidence::forComparisons(2)), $comparison->change);
    }

    public function test_a_variant_with_no_card_behind_the_figure_shows_no_change_and_needs_every_card(): void
    {
        $metric = self::metric(['base' => [1.0, 1.0, 1.0, 1.0, 1.0], 'empty' => [], 'short' => [0.5, 0.5, 0.5]]);

        $headline = self::build($metric, ['base' => 5, 'empty' => 4, 'short' => 6]);

        [$empty, $short] = $headline->comparisons;
        self::assertSame(GoalState::NotEnoughCards, $empty->state);
        self::assertEquals(new ExperimentArm('empty', null, 0), $empty->arm);
        self::assertNull($empty->change);
        self::assertSame(GoalState::NotEnoughCards, $short->state);
        self::assertEquals(new ExperimentArm('short', 0.5, 3), $short->arm);
        self::assertNull($short->change);
    }

    public function test_a_goal_needs_the_floor_in_the_sample_behind_its_range_not_only_in_finished_cards(): void
    {
        $metric = self::metric(['a' => [1.0, 1.0], 'b' => [5.0, 5.0]]);

        $headline = self::build($metric, ['a' => 20, 'b' => 20], baseline: 'a');

        self::assertSame(GoalState::NotEnoughCards, $headline->comparisons[0]->state);
        self::assertEquals(new ExperimentArm('a', 1.0, 2), $headline->baselineArm);
    }

    public function test_a_short_baseline_leaves_every_comparison_short(): void
    {
        $metric = self::metric(['base' => [1.0, 1.0], 'cheap' => [0.5, 0.5, 0.5, 0.5, 0.5]]);

        $headline = self::build($metric, ['base' => 2, 'cheap' => 5]);

        self::assertSame(GoalState::NotEnoughCards, $headline->comparisons[0]->state);
        self::assertNull($headline->comparisons[0]->change);
    }

    /** @return list<ExperimentControl> */
    private static function failedControl(string $variant): array
    {
        return ControlEvaluator::evaluate(
            [['metric' => 'harness-events:diagnostics', 'variant' => $variant, 'expect' => '> 0', 'share' => 0.5]],
            ['harness-events:diagnostics' => [$variant => [0]]],
            [$variant => 1],
        );
    }

    public function test_a_failed_control_on_a_treated_arm_holds_only_that_variant(): void
    {
        $controls = self::failedControl('cheap');

        $headline = self::build(self::metric(self::threeArms()), ['base' => 5, 'cheap' => 5, 'same' => 5], controls: $controls);

        [$cheap, $same] = $headline->comparisons;
        self::assertSame(GoalState::Held, $cheap->state);
        self::assertSame(ControlResult::Failed, $cheap->hold?->reason);
        self::assertNull($cheap->change);
        self::assertSame(0.5, $cheap->arm->point);
        self::assertSame(GoalState::NotClear, $same->state);
        self::assertNull($same->hold);
        self::assertSame($controls, $headline->controls);
    }

    public function test_a_failed_control_on_the_baseline_holds_every_variant(): void
    {
        $headline = self::build(self::metric(self::threeArms()), ['base' => 5, 'cheap' => 5, 'same' => 5], controls: self::failedControl('base'));

        foreach ($headline->comparisons as $comparison) {
            self::assertSame(GoalState::Held, $comparison->state, $comparison->variant);
            self::assertNull($comparison->change);
        }
    }

    public function test_a_control_is_read_before_the_card_floor(): void
    {
        $metric = self::metric(['base' => [1.0], 'cheap' => [5.0]]);

        $headline = self::build($metric, ['base' => 1, 'cheap' => 1], controls: self::failedControl('cheap'));

        self::assertSame(GoalState::Held, $headline->comparisons[0]->state);
    }

    public function test_passing_controls_leave_the_comparison_to_the_data(): void
    {
        $controls = ControlEvaluator::evaluate(
            [['metric' => 'cost', 'variant' => 'cheap', 'expect' => '> 0', 'share' => 0.5]],
            ['cost' => ['cheap' => [1]]],
            ['cheap' => 1],
        );

        $headline = self::build(self::metric(['base' => [1.0], 'cheap' => [1.0]]), ['base' => 1, 'cheap' => 1], controls: $controls);

        self::assertSame(GoalState::NotEnoughCards, $headline->comparisons[0]->state);
        self::assertNull($headline->comparisons[0]->hold);
    }

    public function test_a_goal_with_a_problem_names_it_and_builds_no_comparison(): void
    {
        $headline = self::build(self::metric(self::threeArms()), ['base' => 5, 'cheap' => 5, 'same' => 5], problem: GoalState::UnknownMetric);

        self::assertSame(GoalState::UnknownMetric, $headline->goal->problem);
        self::assertSame('base', $headline->baseline);
        self::assertSame([], $headline->comparisons);
    }

    public function test_a_goal_with_no_direction_names_that_problem_and_builds_no_comparison(): void
    {
        $headline = self::build(self::metric(self::threeArms()), ['base' => 5, 'cheap' => 5, 'same' => 5], direction: null);

        self::assertNull($headline->goal->direction);
        self::assertSame(GoalState::NoDirection, $headline->goal->problem);
        self::assertSame([], $headline->comparisons);
    }

    public function test_a_comparison_state_is_never_a_goal_problem(): void
    {
        $allowed = [GoalState::NotEnoughCards, GoalState::Clear, GoalState::NotClear, GoalState::Held];
        $controls = [new ExperimentControl('cost', 'cheap', '> 0', 1.0, 0.0, 5, 5, ControlResult::Failed)];

        $headline = self::build(self::metric(self::threeArms()), ['base' => 5, 'cheap' => 5, 'same' => 2], controls: $controls);

        self::assertNull($headline->goal->problem);
        foreach ($headline->comparisons as $comparison) {
            self::assertContains($comparison->state, $allowed);
        }
    }

    public function test_an_experiment_with_no_baseline_has_no_comparison(): void
    {
        $guard = self::metric(['base' => [1.0, 2.0], 'cheap' => [1.0, 2.0]]);

        $headline = self::build(self::metric(self::threeArms()), ['base' => 5, 'cheap' => 5, 'same' => 5], baseline: null, confidence: Confidence::forComparisons(0), guards: [$guard]);

        self::assertNull($headline->baseline);
        self::assertNull($headline->baselineArm);
        self::assertSame([], $headline->comparisons);
        self::assertSame(0.95, $headline->confidence->level);
        self::assertNull($headline->guards[0]->baselinePoint);
    }

    public function test_a_guard_reads_each_variant_against_the_baseline(): void
    {
        $samples = ['base' => [1.0, 2.0, 3.0, 2.0, 4.0], 'cheap' => [1.0, 1.5, 2.0, 0.5, 1.0], 'empty' => []];
        $guard = new ExperimentMetric('fix-rounds', array_map(static fn (array $values): ?Interval => [] === $values ? null : new Interval(0.0, 5.0, array_sum($values) / \count($values)), $samples), null, [], new MetricShape(new MetricKey(Metric::Cost)), null, $samples);
        $level = Confidence::forComparisons(2);

        $headline = self::build(self::metric(['base' => [1.0], 'cheap' => [1.0], 'empty' => []]), ['base' => 5, 'cheap' => 5, 'empty' => 0], confidence: $level, guards: [$guard]);

        self::assertSame(2.4, $headline->guards[0]->baselinePoint);
        [$cheap, $empty] = $headline->comparisons;
        self::assertSame('fix-rounds', $cheap->guards[0]->key);
        self::assertSame(1.2, $cheap->guards[0]->point);
        self::assertEquals(Stats::differenceRange($samples['base'], $samples['cheap'], 'test:fix-rounds:guard:cheap', $level), $cheap->guards[0]->change);
        self::assertNull($empty->guards[0]->point);
        self::assertNull($empty->guards[0]->change);
    }
}
