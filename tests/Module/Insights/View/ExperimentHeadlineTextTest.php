<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\View;

use App\Module\Bridge\Experiment\ExperimentArm;
use App\Module\Bridge\Experiment\ExperimentGoal;
use App\Module\Bridge\Experiment\ExperimentGuard;
use App\Module\Bridge\Experiment\ExperimentMetric;
use App\Module\Bridge\Experiment\GoalDirection;
use App\Module\Bridge\Experiment\GoalState;
use App\Module\Bridge\Experiment\Interval;
use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricKey;
use App\Module\Bridge\Metric\MetricShape;
use App\Module\Insights\View\ExperimentHeadlineText;
use App\Tests\Support\TemplateMetricSets;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\XliffFileLoader;
use Symfony\Component\Translation\Translator;

final class ExperimentHeadlineTextTest extends TestCase
{
    private const string MINUS = "\u{2212}";

    public function test_a_duration_goal_says_faster_or_slower(): void
    {
        $lower = self::goal(self::template(TemplateMetricSets::duration()), GoalDirection::Lower, GoalState::Clear, 'sonnet', new Interval(-0.33, -0.09, -0.22), [4.2, 5.4]);
        $higher = self::goal(self::template(TemplateMetricSets::duration()), GoalDirection::Higher, GoalState::Clear, 'sonnet', new Interval(0.09, 0.33, 0.22), [6.6, 5.4]);

        self::assertSame('sonnet is 22% faster than opus', self::text()->sentence($lower));
        self::assertSame('sonnet is 22% slower than opus', self::text()->sentence($higher));
        self::assertSame('9% to 33% lower', self::text()->range($lower));
        self::assertSame('9% to 33% higher', self::text()->range($higher));
    }

    public function test_a_cost_goal_says_cheaper_or_more_expensive(): void
    {
        $lower = self::goal(self::built(Metric::Cost), GoalDirection::Lower, GoalState::Clear, 'sonnet', new Interval(-0.4, -0.1, -0.22), [2.0, 1.0]);
        $higher = self::goal(self::built(Metric::Cost), GoalDirection::Higher, GoalState::Clear, 'sonnet', new Interval(0.1, 0.4, 0.22), [1.0, 2.0]);

        self::assertSame('sonnet is 22% cheaper than opus', self::text()->sentence($lower));
        self::assertSame('sonnet is 22% more expensive than opus', self::text()->sentence($higher));
    }

    public function test_a_count_goal_says_fewer_or_more(): void
    {
        $count = self::template(TemplateMetricSets::count());

        self::assertSame('sonnet has 30% fewer than opus', self::text()->sentence(self::goal($count, GoalDirection::Lower, GoalState::Clear, 'sonnet', new Interval(-0.5, -0.1, -0.3), [1.0, 0.7])));
        self::assertSame('sonnet has 30% more than opus', self::text()->sentence(self::goal($count, GoalDirection::Higher, GoalState::Clear, 'sonnet', new Interval(0.1, 0.5, 0.3), [1.0, 1.3])));
    }

    public function test_another_goal_says_lower_or_higher(): void
    {
        $tokens = self::goal(self::built(Metric::OutputTokens), GoalDirection::Lower, GoalState::Clear, 'sonnet', new Interval(-0.4, -0.1, -0.22), [2.0, 1.0]);

        self::assertSame('sonnet is 22% lower than opus', self::text()->sentence($tokens));
    }

    public function test_a_range_across_zero_names_both_sides(): void
    {
        $goal = self::goal(self::template(TemplateMetricSets::duration()), GoalDirection::Lower, GoalState::NotClear, 'sonnet', new Interval(-0.14, 0.03, -0.08), [4.9, 5.3]);

        self::assertSame('14% lower to 3% higher', self::text()->range($goal));
    }

    public function test_a_ratio_goal_counts_points(): void
    {
        $rate = self::template(TemplateMetricSets::rate());
        $higher = self::goal($rate, GoalDirection::Higher, GoalState::Clear, 'sonnet', new Interval(0.03, 0.09, 0.06), [0.9, 0.84], relative: false);
        $across = self::goal($rate, GoalDirection::Higher, GoalState::NotClear, 'sonnet', new Interval(-0.02, 0.07, 0.01), [0.9, 0.89], relative: false);

        self::assertSame('sonnet is 6 points higher than opus', self::text()->sentence($higher));
        self::assertSame('3 to 9 points higher', self::text()->range($higher));
        self::assertSame('sonnet is 1 point higher than opus', self::text()->sentence($across));
        self::assertSame('2 points lower to 7 points higher', self::text()->range($across));
    }

    public function test_a_goal_without_an_answer_has_its_own_sentence(): void
    {
        $metric = self::built(Metric::Cost);

        self::assertSame('Not enough cards yet', self::text()->sentence(self::goal($metric, GoalDirection::Lower, GoalState::NotEnoughCards, null, null, [1.0, 2.0])));
        self::assertSame('The variants are level', self::text()->sentence(self::goal($metric, GoalDirection::Lower, GoalState::Clear, null, null, [1.0, 1.0])));
        self::assertSame('sonnet is ahead of opus', self::text()->sentence(self::goal($metric, GoalDirection::Higher, GoalState::NotClear, 'sonnet', null, [1.0, 0.0])));
        self::assertNull(self::text()->range(self::goal($metric, GoalDirection::Lower, GoalState::Clear, null, null, [1.0, 1.0])));
    }

    public function test_a_point_uses_the_unit_of_its_metric(): void
    {
        self::assertSame('4.2 h', self::text()->point(self::metric(self::template(TemplateMetricSets::duration())), 4.2));
        self::assertSame('$1.50', self::text()->point(self::metric(self::built(Metric::Cost)), 1.5));
        self::assertSame('92%', self::text()->point(self::metric(self::template(TemplateMetricSets::rate())), 0.92));
        self::assertNull(self::text()->point(self::metric(self::built(Metric::Cost)), null));
    }

    public function test_a_rate_guard_shows_both_rates_and_a_change_in_points(): void
    {
        $guard = new ExperimentGuard(self::metric(self::template(TemplateMetricSets::rate())), self::arms([0.92, 0.86]), new Interval(-0.12, 0.0, -0.06));

        self::assertSame('92% to 86%', self::text()->guardChange($guard));
        self::assertSame(self::MINUS.'12 to 0 pp', self::text()->guardRange($guard));
    }

    public function test_a_count_guard_shows_a_signed_change(): void
    {
        $guard = new ExperimentGuard(self::metric(self::template(TemplateMetricSets::count())), self::arms([1.0, 1.1]), new Interval(-0.2, 0.4, 0.1));

        self::assertSame('+0.1', self::text()->guardChange($guard));
        self::assertSame(self::MINUS.'0.2 to +0.4', self::text()->guardRange($guard));
    }

    public function test_a_money_guard_uses_the_money_format(): void
    {
        $guard = new ExperimentGuard(self::metric(self::built(Metric::Cost)), self::arms([2.0, 1.5]), new Interval(-0.8, -0.2, -0.5));

        self::assertSame(self::MINUS.'$0.50', self::text()->guardChange($guard));
        self::assertSame(self::MINUS.'$0.80 to '.self::MINUS.'$0.20', self::text()->guardRange($guard));
    }

    public function test_a_winner_whose_change_rounds_to_zero_still_leads(): void
    {
        $cost = self::goal(self::built(Metric::Cost), GoalDirection::Lower, GoalState::Clear, 'sonnet', new Interval(-0.006, -0.002, -0.004), [1.0, 0.996]);
        $rate = self::goal(self::template(TemplateMetricSets::rate()), GoalDirection::Higher, GoalState::Clear, 'sonnet', new Interval(0.002, 0.006, 0.004), [0.9, 0.904], relative: false);

        self::assertSame('sonnet is under 1% cheaper than opus', self::text()->sentence($cost));
        self::assertSame('sonnet is under 1 point higher than opus', self::text()->sentence($rate));
    }

    public function test_a_goal_with_a_problem_names_no_winner(): void
    {
        $goal = new ExperimentGoal(new ExperimentMetric('cost', [], false), null, GoalState::NoDirection, self::arms([1.0, 2.0]), null, null, true);

        self::assertSame('The variants are level', self::text()->sentence($goal));
        self::assertNull(self::text()->range($goal));
    }

    public function test_a_change_that_rounds_to_zero_has_no_sign(): void
    {
        $guard = new ExperimentGuard(self::metric(self::template(TemplateMetricSets::count())), self::arms([1.0, 1.0]), new Interval(-0.04, 0.04, 0.01));

        self::assertSame('0', self::text()->guardChange($guard));
        self::assertSame('0 to 0', self::text()->guardRange($guard));
    }

    public function test_a_guard_with_no_data_has_no_text(): void
    {
        $count = new ExperimentGuard(self::metric(self::template(TemplateMetricSets::count())), self::arms([null, 1.0]), null);
        $rate = new ExperimentGuard(self::metric(self::template(TemplateMetricSets::rate())), self::arms([null, 0.9]), null);

        self::assertNull(self::text()->guardChange($count));
        self::assertNull(self::text()->guardRange($count));
        self::assertNull(self::text()->guardChange($rate));
        self::assertNull(self::text()->guardRange($rate));
    }

    private static function text(): ExperimentHeadlineText
    {
        $translator = new Translator('en');
        $translator->addLoader('xlf', new XliffFileLoader());
        $translator->addResource('xlf', \dirname(__DIR__, 4).'/translations/messages.en.xlf', 'en');

        return new ExperimentHeadlineText($translator, 'en');
    }

    /**
     * @param list<?float> $points
     *
     * @return list<ExperimentArm>
     */
    private static function arms(array $points): array
    {
        return [new ExperimentArm('opus', $points[0], 6), new ExperimentArm('sonnet', $points[1], 6)];
    }

    /** @param array{float, float} $points the points of opus and sonnet */
    private static function goal(MetricShape $shape, GoalDirection $direction, GoalState $state, ?string $winner, ?Interval $change, array $points, bool $relative = true): ExperimentGoal
    {
        return new ExperimentGoal(self::metric($shape), $direction, $state, self::arms($points), $winner, $change, $relative);
    }

    private static function metric(MetricShape $shape): ExperimentMetric
    {
        return new ExperimentMetric('goal', [], true, shape: $shape);
    }

    private static function built(Metric $metric): MetricShape
    {
        return new MetricShape(new MetricKey($metric));
    }

    private static function template(\App\Module\Workflow\Contract\TemplateMetric $metric): MetricShape
    {
        return MetricShape::template($metric);
    }
}
