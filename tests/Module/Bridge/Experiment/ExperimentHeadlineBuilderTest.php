<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Experiment;

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
}
