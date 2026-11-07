<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\View;

use App\Module\Bridge\Experiment\Confidence;
use App\Module\Bridge\Experiment\ExperimentArm;
use App\Module\Bridge\Experiment\ExperimentComparison;
use App\Module\Bridge\Experiment\ExperimentGoal;
use App\Module\Bridge\Experiment\ExperimentHeadline;
use App\Module\Bridge\Experiment\ExperimentMetric;
use App\Module\Bridge\Experiment\GoalDirection;
use App\Module\Bridge\Experiment\GoalState;
use App\Module\Bridge\Experiment\Interval;
use App\Module\Insights\View\ExperimentResultRows;
use App\Module\Insights\View\ExperimentResultTone;
use PHPUnit\Framework\TestCase;

final class ExperimentResultRowsTest extends TestCase
{
    public function test_a_headline_with_no_baseline_or_a_goal_problem_has_no_rows(): void
    {
        self::assertNull(ExperimentResultRows::of(self::headline(GoalDirection::Lower, [], baseline: null), true));
        self::assertNull(ExperimentResultRows::of(self::headline(GoalDirection::Lower, [], problem: GoalState::NoDirection), true));
        self::assertNull(ExperimentResultRows::of(self::headline(null, []), true));
    }

    public function test_the_tone_follows_the_sign_of_the_change_and_the_direction_of_the_goal(): void
    {
        $changes = [self::row('a', new Interval(-0.4, -0.2, -0.3)), self::row('b', new Interval(0.1, 0.3, 0.2)), self::row('c', new Interval(0.0, 0.0, 0.0)), self::row('d', null)];

        $lower = ExperimentResultRows::of(self::headline(GoalDirection::Lower, $changes), false);
        $higher = ExperimentResultRows::of(self::headline(GoalDirection::Higher, $changes), false);

        self::assertSame(['better', 'worse', 'level', 'none'], array_map(static fn ($row): string => $row->tone->value, $lower->rows ?? []));
        self::assertSame(['worse', 'better', 'level', 'none'], array_map(static fn ($row): string => $row->tone->value, $higher->rows ?? []));
    }

    public function test_the_rows_share_one_scale_with_a_margin_and_the_baseline_sits_at_zero(): void
    {
        $result = ExperimentResultRows::of(self::headline(GoalDirection::Lower, [self::row('a', new Interval(-0.4, -0.2, -0.3)), self::row('b', new Interval(0.0, 0.2, 0.1))]), false);

        self::assertNotNull($result);
        self::assertEqualsWithDelta(63.89, (float) $result->baselineAt, 0.01);
        $first = $result->rows[0]->strip;
        $second = $result->rows[1]->strip;
        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertEqualsWithDelta(8.33, $first->low, 0.01);
        self::assertEqualsWithDelta(36.11, $first->high, 0.01);
        self::assertEqualsWithDelta(63.89, $second->low, 0.01);
        self::assertEqualsWithDelta(91.67, $second->high, 0.01);
    }

    public function test_the_rows_sort_by_the_change_best_first_and_the_rows_with_no_change_follow(): void
    {
        $rows = [self::row('none', null), self::row('mid', new Interval(-0.2, -0.1, -0.15)), self::row('worse', new Interval(0.1, 0.2, 0.15)), self::row('best', new Interval(-0.5, -0.3, -0.4))];

        $lower = ExperimentResultRows::of(self::headline(GoalDirection::Lower, $rows), true);
        $higher = ExperimentResultRows::of(self::headline(GoalDirection::Higher, $rows), true);
        $page = ExperimentResultRows::of(self::headline(GoalDirection::Lower, $rows), false);

        self::assertSame(['best', 'mid', 'worse', 'none'], self::names($lower));
        self::assertSame(['worse', 'mid', 'best', 'none'], self::names($higher));
        self::assertSame(['none', 'mid', 'worse', 'best'], self::names($page));
    }

    public function test_a_ratio_goal_scales_a_change_in_points_the_same_way(): void
    {
        $result = ExperimentResultRows::of(self::headline(GoalDirection::Higher, [self::row('a', new Interval(0.02, 0.1, 0.06), relative: false)]), true);

        self::assertNotNull($result);
        self::assertSame(ExperimentResultTone::Better, $result->rows[0]->tone);
        self::assertNotNull($result->baselineAt);
        self::assertLessThan($result->rows[0]->strip?->low, $result->baselineAt);
    }

    public function test_a_block_with_no_change_draws_no_strip_and_no_baseline_line(): void
    {
        $result = ExperimentResultRows::of(self::headline(GoalDirection::Lower, [self::row('a', null), self::row('b', null)]), true);

        self::assertNotNull($result);
        self::assertNull($result->baselineAt);
        self::assertNull($result->rows[0]->strip);
        self::assertNull($result->rows[1]->strip);
    }

    public function test_a_scale_with_zero_span_puts_every_position_at_50(): void
    {
        $result = ExperimentResultRows::of(self::headline(GoalDirection::Lower, [self::row('a', new Interval(0.0, 0.0, 0.0)), self::row('b', new Interval(0.0, 0.0, 0.0))]), true);

        self::assertNotNull($result);
        self::assertSame(50.0, $result->baselineAt);
        foreach ($result->rows as $row) {
            self::assertSame([50.0, 50.0, 50.0], [$row->strip?->low, $row->strip?->point, $row->strip?->high]);
            self::assertSame(ExperimentResultTone::Level, $row->tone);
        }
    }

    /** @return list<string> */
    private static function names(?ExperimentResultRows $result): array
    {
        return array_map(static fn ($row): string => $row->comparison->variant, $result->rows ?? []);
    }

    private static function row(string $variant, ?Interval $change, bool $relative = true): ExperimentComparison
    {
        return new ExperimentComparison($variant, null === $change ? GoalState::NotEnoughCards : GoalState::Clear, new ExperimentArm($variant, 1.0, 6), $relative, $change, null, []);
    }

    /** @param list<ExperimentComparison> $comparisons */
    private static function headline(?GoalDirection $direction, array $comparisons, ?string $baseline = 'base', ?GoalState $problem = null): ExperimentHeadline
    {
        return new ExperimentHeadline(
            new ExperimentGoal(new ExperimentMetric('cost', [], null), $direction, $problem),
            $baseline,
            new Confidence(0.95),
            null === $baseline ? null : new ExperimentArm($baseline, 2.0, 6),
            $comparisons,
            [],
        );
    }
}
