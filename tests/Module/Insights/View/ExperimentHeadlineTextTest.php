<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\View;

use App\Module\Bridge\Experiment\Confidence;
use App\Module\Bridge\Experiment\ControlResult;
use App\Module\Bridge\Experiment\ExperimentArm;
use App\Module\Bridge\Experiment\ExperimentComparison;
use App\Module\Bridge\Experiment\ExperimentControl;
use App\Module\Bridge\Experiment\ExperimentGoal;
use App\Module\Bridge\Experiment\ExperimentGuard;
use App\Module\Bridge\Experiment\ExperimentGuardChange;
use App\Module\Bridge\Experiment\ExperimentMetric;
use App\Module\Bridge\Experiment\GoalDirection;
use App\Module\Bridge\Experiment\GoalHold;
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
        $faster = self::subject(self::template(TemplateMetricSets::duration()), GoalDirection::Lower, GoalState::Clear, new Interval(-0.33, -0.09, -0.22), [4.2, 5.4]);
        $slower = self::subject(self::template(TemplateMetricSets::duration()), GoalDirection::Lower, GoalState::Clear, new Interval(0.09, 0.33, 0.22), [6.6, 5.4]);

        self::assertSame('22% faster', self::text()->change(...self::pair($faster)));
        self::assertSame('22% slower', self::text()->change(...self::pair($slower)));
        self::assertSame('9% to 33% lower', self::text()->range($faster[1]));
        self::assertSame('9% to 33% higher', self::text()->range($slower[1]));
    }

    public function test_the_change_reads_the_variant_against_the_baseline_whichever_arm_leads(): void
    {
        $higherIsBetter = self::subject(self::built(Metric::Cost), GoalDirection::Higher, GoalState::Clear, new Interval(-0.4, -0.1, -0.22), [2.0, 1.0]);

        self::assertSame('22% cheaper', self::text()->change(...self::pair($higherIsBetter)));
    }

    public function test_a_cost_goal_says_cheaper_or_more_expensive(): void
    {
        $lower = self::subject(self::built(Metric::Cost), GoalDirection::Lower, GoalState::Clear, new Interval(-0.4, -0.1, -0.22), [2.0, 1.0]);
        $higher = self::subject(self::built(Metric::Cost), GoalDirection::Lower, GoalState::Clear, new Interval(0.1, 0.4, 0.22), [1.0, 2.0]);

        self::assertSame('22% cheaper', self::text()->change(...self::pair($lower)));
        self::assertSame('22% more expensive', self::text()->change(...self::pair($higher)));
    }

    public function test_a_count_goal_says_fewer_or_more(): void
    {
        $count = self::template(TemplateMetricSets::count());

        self::assertSame('30% fewer', self::text()->change(...self::pair(self::subject($count, GoalDirection::Lower, GoalState::Clear, new Interval(-0.5, -0.1, -0.3), [1.0, 0.7]))));
        self::assertSame('30% more', self::text()->change(...self::pair(self::subject($count, GoalDirection::Higher, GoalState::Clear, new Interval(0.1, 0.5, 0.3), [1.0, 1.3]))));
    }

    public function test_another_goal_says_lower_or_higher(): void
    {
        $tokens = self::subject(self::built(Metric::OutputTokens), GoalDirection::Lower, GoalState::Clear, new Interval(-0.4, -0.1, -0.22), [2.0, 1.0]);

        self::assertSame('22% lower', self::text()->change(...self::pair($tokens)));
    }

    public function test_a_range_across_zero_names_both_sides(): void
    {
        $goal = self::subject(self::template(TemplateMetricSets::duration()), GoalDirection::Lower, GoalState::NotClear, new Interval(-0.14, 0.03, -0.08), [4.9, 5.3]);

        self::assertSame('14% lower to 3% higher', self::text()->range($goal[1]));
    }

    public function test_a_ratio_goal_counts_percentage_points(): void
    {
        $rate = self::template(TemplateMetricSets::rate());
        $higher = self::subject($rate, GoalDirection::Higher, GoalState::Clear, new Interval(0.03, 0.09, 0.06), [0.9, 0.84], relative: false);
        $across = self::subject($rate, GoalDirection::Higher, GoalState::NotClear, new Interval(-0.02, 0.07, 0.01), [0.9, 0.89], relative: false);

        self::assertSame('6 pp higher', self::text()->change(...self::pair($higher)));
        self::assertSame('3 to 9 pp higher', self::text()->range($higher[1]));
        self::assertSame('1 pp higher', self::text()->change(...self::pair($across)));
        self::assertSame('2 pp lower to 7 pp higher', self::text()->range($across[1]));
    }

    public function test_a_comparison_with_no_change_has_no_change_text(): void
    {
        $metric = self::built(Metric::Cost);

        self::assertNull(self::text()->change(...self::pair(self::subject($metric, GoalDirection::Lower, GoalState::NotEnoughCards, null, [1.0, 2.0]))));
        self::assertNull(self::text()->range(self::subject($metric, GoalDirection::Lower, GoalState::Clear, null, [1.0, 1.0])[1]));
    }

    public function test_a_change_of_exactly_zero_reads_no_change(): void
    {
        $subject = self::subject(self::built(Metric::Cost), GoalDirection::Lower, GoalState::NotClear, new Interval(-0.1, 0.1, 0.0), [1.0, 1.0]);

        self::assertSame('No change', self::text()->change(...self::pair($subject)));
    }

    public function test_a_point_uses_the_unit_of_its_metric(): void
    {
        self::assertSame('4.2 h', self::text()->point(self::metric(self::template(TemplateMetricSets::duration())), 4.2));
        self::assertSame('$1.50', self::text()->point(self::metric(self::built(Metric::Cost)), 1.5));
        self::assertSame('92%', self::text()->point(self::metric(self::template(TemplateMetricSets::rate())), 0.92));
        self::assertNull(self::text()->point(self::metric(self::built(Metric::Cost)), null));
    }

    public function test_a_rate_guard_shows_a_change_in_points_with_its_range(): void
    {
        $guard = new ExperimentGuard(self::metric(self::template(TemplateMetricSets::rate())), 0.92);

        self::assertSame(self::MINUS.'6 pp ('.self::MINUS.'12 to 0)', self::text()->guardChange($guard, new ExperimentGuardChange('goal', 0.86, new Interval(-0.12, 0.0, -0.06))));
        self::assertSame('+5 pp ('.self::MINUS.'6 to +15)', self::text()->guardChange($guard, new ExperimentGuardChange('goal', 0.93, new Interval(-0.06, 0.15, 0.05))));
        self::assertSame('92%', self::text()->point($guard->metric, $guard->baselinePoint));
    }

    public function test_a_count_guard_shows_a_signed_change_with_its_range(): void
    {
        $guard = new ExperimentGuard(self::metric(self::template(TemplateMetricSets::count())), 1.0);

        self::assertSame('+0.1 ('.self::MINUS.'0.2 to +0.4)', self::text()->guardChange($guard, new ExperimentGuardChange('goal', 1.1, new Interval(-0.2, 0.4, 0.1))));
    }

    public function test_a_money_guard_uses_the_money_format(): void
    {
        $guard = new ExperimentGuard(self::metric(self::built(Metric::Cost)), 2.0);

        self::assertSame(self::MINUS.'$0.50 ('.self::MINUS.'$0.80 to '.self::MINUS.'$0.20)', self::text()->guardChange($guard, new ExperimentGuardChange('goal', 1.5, new Interval(-0.8, -0.2, -0.5))));
    }

    public function test_the_range_level_reads_as_a_percent_and_as_a_count_in_100(): void
    {
        self::assertSame('97.5%', self::text()->level(new Confidence(0.975)));
        self::assertSame('97.5', self::text()->levelOutOf100(new Confidence(0.975)));
        self::assertSame('98.3%', self::text()->level(Confidence::forComparisons(3)));
        self::assertSame('95%', self::text()->level(Confidence::forComparisons(1)));
        self::assertSame('95', self::text()->levelOutOf100(Confidence::forComparisons(0)));
    }

    public function test_a_change_that_rounds_to_zero_reads_under_one_unit(): void
    {
        $cost = self::subject(self::built(Metric::Cost), GoalDirection::Lower, GoalState::Clear, new Interval(-0.006, -0.002, -0.004), [1.0, 0.996]);
        $rate = self::subject(self::template(TemplateMetricSets::rate()), GoalDirection::Higher, GoalState::Clear, new Interval(0.002, 0.006, 0.004), [0.9, 0.904], relative: false);

        self::assertSame('under 1% cheaper', self::text()->change(...self::pair($cost)));
        self::assertSame('under 1 pp higher', self::text()->change(...self::pair($rate)));
    }

    public function test_a_change_that_rounds_to_zero_has_no_sign(): void
    {
        $guard = new ExperimentGuard(self::metric(self::template(TemplateMetricSets::count())), 1.0);
        $change = new ExperimentGuardChange('goal', 1.0, new Interval(-0.04, 0.04, 0.01));

        self::assertSame('0 (0 to 0)', self::text()->guardChange($guard, $change));
    }

    public function test_a_guard_with_no_data_has_no_text(): void
    {
        $count = new ExperimentGuard(self::metric(self::template(TemplateMetricSets::count())), null);
        $rate = new ExperimentGuard(self::metric(self::template(TemplateMetricSets::rate())), null);
        $none = new ExperimentGuardChange('goal', 0.9, null);

        self::assertNull(self::text()->guardChange($count, $none));
        self::assertNull(self::text()->guardChange($rate, $none));
    }

    public function test_a_held_comparison_counts_the_controls_on_its_two_arms(): void
    {
        $control = new ExperimentControl('cost', 'sonnet', '> 0', 1.0, 0.0, 1, 1, ControlResult::Failed);
        [, $comparison] = self::subject(self::built(Metric::Cost), GoalDirection::Lower, GoalState::Held, null, [2.0, 1.0]);
        $held = new ExperimentComparison('sonnet', GoalState::Held, $comparison->arm, true, null, new GoalHold(ControlResult::Failed, [$control], 3), []);

        self::assertSame('Controls failed: 1 of 3. The comparison names no winner.', self::text()->holdReason($held));
    }

    public function test_a_hold_with_no_data_or_an_unknown_metric_says_so(): void
    {
        [, $comparison] = self::subject(self::built(Metric::Cost), GoalDirection::Lower, GoalState::Held, null, [2.0, 1.0]);
        $noData = new ExperimentControl('cost', 'sonnet', '> 0', 1.0, null, 0, 1, ControlResult::NoData);
        $unknown = new ExperimentControl('nope', 'sonnet', '> 0', 1.0, null, 0, 1, ControlResult::UnknownMetric);

        $withoutData = new ExperimentComparison('sonnet', GoalState::Held, $comparison->arm, true, null, new GoalHold(ControlResult::NoData, [$noData], 2), []);
        $withUnknown = new ExperimentComparison('sonnet', GoalState::Held, $comparison->arm, true, null, new GoalHold(ControlResult::UnknownMetric, [$unknown], 2), []);

        self::assertSame('Controls with no data: 1 of 2. The comparison names no winner.', self::text()->holdReason($withoutData));
        self::assertSame('Controls with an unknown metric: 1 of 2. The comparison names no winner.', self::text()->holdReason($withUnknown));
    }

    public function test_a_short_comparison_names_each_side_that_needs_more_cards(): void
    {
        $baseline = new ExperimentArm('opus', 1.0, 3);
        $comparison = new ExperimentComparison('sonnet', GoalState::NotEnoughCards, new ExperimentArm('sonnet', 2.0, 4), true, null, null, []);
        $oneShort = new ExperimentComparison('sonnet', GoalState::NotEnoughCards, new ExperimentArm('sonnet', null, 0), true, null, null, []);

        self::assertSame('opus needs 2 more cards, sonnet needs 1 more card', self::text()->needs($comparison, $baseline));
        self::assertSame('sonnet needs 5 more cards', self::text()->needs($oneShort, new ExperimentArm('opus', 1.0, 9)));
    }

    public function test_a_metric_that_names_a_bucket_or_an_event_reads_its_family_name(): void
    {
        self::assertSame('Harness events: diagnostics', self::text()->metricName(new ExperimentMetric('harness-events:diagnostics', [], null)));
        self::assertSame('Calls in lsp', self::text()->metricName(new ExperimentMetric('bucket-calls:lsp', [], null)));
        self::assertSame('Time in local-gate', self::text()->metricName(new ExperimentMetric('bucket-time:local-gate', [], null)));
        self::assertSame('Time in tests, subagents included', self::text()->metricName(new ExperimentMetric('bucket-time-all:tests', [], null)));
        self::assertSame('Cost per merged card', self::text()->metricName(new ExperimentMetric('cost', [], null)));
        self::assertSame('Review rounds', self::text()->metricName(new ExperimentMetric('review-rounds', [], null, label: 'Review rounds')));
    }

    private static function text(): ExperimentHeadlineText
    {
        $translator = new Translator('en');
        $translator->addLoader('xlf', new XliffFileLoader());
        $translator->addResource('xlf', \dirname(__DIR__, 4).'/translations/messages.en.xlf', 'en');

        return new ExperimentHeadlineText($translator, 'en');
    }

    /**
     * @param array{ExperimentGoal, ExperimentComparison, ExperimentArm} $subject
     *
     * @return array{ExperimentGoal, ExperimentComparison}
     */
    private static function pair(array $subject): array
    {
        return [$subject[0], $subject[1]];
    }

    /**
     * @param array{float, float} $points the points of the baseline opus and of the variant sonnet
     *
     * @return array{ExperimentGoal, ExperimentComparison, ExperimentArm}
     */
    private static function subject(MetricShape $shape, ?GoalDirection $direction, GoalState $state, ?Interval $change, array $points, bool $relative = true): array
    {
        $baseline = new ExperimentArm('opus', $points[0], 6);

        return [
            new ExperimentGoal(self::metric($shape), $direction),
            new ExperimentComparison('sonnet', $state, new ExperimentArm('sonnet', $points[1], 6), $relative, $change, null, []),
            $baseline,
        ];
    }

    private static function metric(MetricShape $shape): ExperimentMetric
    {
        return new ExperimentMetric('goal', [], null, shape: $shape);
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
