<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Experiment;

use App\Module\Bridge\Experiment\ControlEvaluator;
use App\Module\Bridge\Experiment\ControlResult;
use App\Module\Bridge\Experiment\ExperimentHeadlineBuilder;
use App\Module\Bridge\Experiment\ExperimentMetric;
use App\Module\Bridge\Experiment\GoalDirection;
use App\Module\Bridge\Experiment\GoalState;
use App\Module\Bridge\Experiment\Interval;
use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricKey;
use App\Module\Bridge\Metric\MetricShape;
use PHPUnit\Framework\TestCase;

final class ExperimentHeadlineBuilderTest extends TestCase
{
    public function test_a_goal_needs_the_floor_in_the_sample_behind_its_range_not_only_in_finished_cards(): void
    {
        $metric = new ExperimentMetric(
            Metric::Cost->value,
            ['a' => new Interval(1.0, 1.0, 1.0), 'b' => new Interval(5.0, 5.0, 5.0)],
            false,
            [],
            new MetricShape(new MetricKey(Metric::Cost)),
            null,
            ['a' => [1.0, 1.0], 'b' => [5.0, 5.0]],
        );

        $headline = ExperimentHeadlineBuilder::build('test', ['a', 'b'], ['a' => 20, 'b' => 20], $metric, GoalDirection::Lower, []);

        self::assertSame(GoalState::NotEnoughCards, $headline->goal->state);
        self::assertNull($headline->goal->winner);
    }

    public function test_a_failed_control_holds_the_goal_before_the_card_floor_is_read(): void
    {
        $metric = new ExperimentMetric(
            Metric::Cost->value,
            ['a' => new Interval(1.0, 1.0, 1.0), 'b' => new Interval(5.0, 5.0, 5.0)],
            false,
            [],
            new MetricShape(new MetricKey(Metric::Cost)),
            null,
            ['a' => [1.0], 'b' => [5.0]],
        );
        $controls = ControlEvaluator::evaluate(
            [['metric' => 'harness-events:diagnostics', 'variant' => 'a', 'expect' => '> 0', 'share' => 0.5]],
            ['harness-events:diagnostics' => ['a' => [0]]],
            ['a' => 1, 'b' => 1],
        );

        $headline = ExperimentHeadlineBuilder::build('test', ['a', 'b'], ['a' => 1, 'b' => 1], $metric, GoalDirection::Lower, [], null, $controls);

        self::assertSame(GoalState::Held, $headline->goal->state);
        self::assertSame(ControlResult::Failed, $headline->goal->hold?->reason);
        self::assertNull($headline->goal->winner);
        self::assertSame([1.0, 5.0], array_map(static fn ($arm): ?float => $arm->point, $headline->goal->arms));
        self::assertSame($controls, $headline->controls);
    }

    public function test_passing_controls_leave_the_goal_to_the_data(): void
    {
        $metric = new ExperimentMetric(Metric::Cost->value, [], false, [], new MetricShape(new MetricKey(Metric::Cost)));
        $controls = ControlEvaluator::evaluate(
            [['metric' => 'cost', 'variant' => 'a', 'expect' => '> 0', 'share' => 0.5]],
            ['cost' => ['a' => [1]]],
            ['a' => 1],
        );

        $headline = ExperimentHeadlineBuilder::build('test', ['a', 'b'], ['a' => 1, 'b' => 1], $metric, GoalDirection::Lower, [], null, $controls);

        self::assertSame(GoalState::NotEnoughCards, $headline->goal->state);
        self::assertNull($headline->goal->hold);
    }
}
