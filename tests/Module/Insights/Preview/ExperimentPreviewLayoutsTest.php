<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Preview;

use App\Module\Bridge\Experiment\Confidence;
use App\Module\Bridge\Experiment\ControlResult;
use App\Module\Bridge\Experiment\ExperimentArm;
use App\Module\Bridge\Experiment\ExperimentComparison;
use App\Module\Bridge\Experiment\ExperimentGoal;
use App\Module\Bridge\Experiment\ExperimentHeadline;
use App\Module\Bridge\Experiment\ExperimentMetric;
use App\Module\Bridge\Experiment\ExperimentVariant;
use App\Module\Bridge\Experiment\GoalDirection;
use App\Module\Bridge\Experiment\GoalHold;
use App\Module\Bridge\Experiment\GoalState;
use App\Module\Bridge\Experiment\Interval;
use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricKey;
use App\Module\Bridge\Metric\MetricShape;
use App\Module\Insights\Preview\ExperimentPreviewLayout;
use App\Module\Insights\Preview\ExperimentPreviewLayouts;
use App\Module\Insights\View\ExperimentResultTone;
use App\Module\Insights\View\PublicExperimentResult;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ExperimentPreviewLayoutsTest extends KernelTestCase
{
    private const array COSTS = ['no-lsp' => 4.12, 'sonnet' => 2.84, 'lsp' => 3.70, 'codex' => 4.45];

    public function test_a_goal_with_a_baseline_gives_rows_sorted_by_the_change_with_the_header_of_the_page(): void
    {
        $layout = $this->layout(self::experiment([
            self::comparison('codex', GoalState::NotClear, 4.45, new Interval(0.01, 0.15, 0.08)),
            self::comparison('lsp', GoalState::NotClear, 3.70, new Interval(-0.2, 0.0, -0.1)),
            self::comparison('sonnet', GoalState::Clear, 2.84, new Interval(-0.4, -0.2, -0.31)),
        ]));

        self::assertTrue($layout->rows);
        self::assertSame('Cost per merged card', $layout->title);
        self::assertSame('each variant against no-lsp ($4.12) · lower is better', self::tight($layout->subtitle));
        self::assertSame(['sonnet', 'lsp', 'codex'], array_column($layout->items, 'name'));
        self::assertSame(['−31%', '−10%', '+8%'], array_column($layout->items, 'change'));
        self::assertSame(['$2.84', '$3.70', '$4.45'], array_map(self::tight(...), array_column($layout->items, 'value')));
        self::assertSame(['Clear', 'Not clear yet', 'Not clear yet'], array_column($layout->items, 'chip'));
        self::assertSame(['ok', 'pending', 'pending'], array_column($layout->items, 'chipTone'));
        self::assertSame([ExperimentResultTone::Better, ExperimentResultTone::Better, ExperimentResultTone::Worse], array_column($layout->items, 'tone'));
        self::assertNotNull($layout->baselineAt);
        self::assertNotNull($layout->items[0]->strip);
        self::assertSame(0, $layout->more);
    }

    public function test_a_variant_short_of_data_shows_its_chip_and_a_dash_and_sorts_after_the_rows_with_a_change(): void
    {
        $short = new ExperimentComparison('codex', GoalState::NotEnoughCards, new ExperimentArm('codex', null, 2), true, null, null, []);

        $layout = $this->layout(self::experiment([$short, self::comparison('sonnet', GoalState::Clear, 2.84, new Interval(-0.4, -0.2, -0.31))]));

        self::assertSame(['sonnet', 'codex'], array_column($layout->items, 'name'));
        $codex = $layout->items[1];
        self::assertSame('–', $codex->value);
        self::assertNull($codex->change);
        self::assertNull($codex->strip);
        self::assertSame('neutral', $codex->chipTone);
        self::assertSame('codex needs 3 more cards', $codex->chip);
    }

    public function test_a_comparison_held_by_a_control_shows_the_failed_chip_or_the_neutral_no_data_chip(): void
    {
        $held = static fn (string $name, ControlResult $reason): ExperimentComparison => new ExperimentComparison($name, GoalState::Held, new ExperimentArm($name, 3.0, 6), true, null, new GoalHold($reason, [], 1), []);

        $layout = $this->layout(self::experiment([$held('a', ControlResult::Failed), $held('b', ControlResult::NoData)]));

        self::assertSame(['Control failed', 'No data'], array_column($layout->items, 'chip'));
        self::assertSame(['failed', 'neutral'], array_column($layout->items, 'chipTone'));
        self::assertNull($layout->baselineAt);
    }

    public function test_the_four_row_limit_wins_over_a_variant_short_of_data(): void
    {
        $comparisons = [self::comparison('v1', GoalState::Clear, 3.0, new Interval(-0.5, -0.4, -0.45))];
        foreach (range(2, 5) as $i) {
            $comparisons[] = self::comparison('v'.$i, GoalState::NotClear, 3.0, new Interval(-0.3, -0.1, -0.2 + $i / 100));
        }
        $comparisons[] = new ExperimentComparison('codex', GoalState::NotEnoughCards, new ExperimentArm('codex', null, 2), true, null, null, []);

        $layout = $this->layout(self::experiment($comparisons));

        self::assertCount(4, $layout->items);
        self::assertSame(2, $layout->more);
        self::assertNotContains('codex', array_column($layout->items, 'name'));
    }

    public function test_the_scale_of_the_strips_follows_the_rows_that_are_shown(): void
    {
        $comparisons = [];
        foreach (range(1, 4) as $i) {
            $comparisons[] = self::comparison('v'.$i, GoalState::Clear, 3.0, new Interval(-0.2 - $i / 100, -0.1, -0.15 - $i / 100));
        }
        $comparisons[] = self::comparison('wild', GoalState::NotClear, 9.0, new Interval(2.0, 4.0, 3.0));

        $layout = $this->layout(self::experiment($comparisons));

        self::assertSame(1, $layout->more);
        foreach ($layout->items as $item) {
            self::assertNotNull($item->strip);
            self::assertGreaterThan(5.0, $item->strip->high - $item->strip->low);
        }
    }

    public function test_an_experiment_with_no_baseline_gives_cards_best_first(): void
    {
        $layout = $this->layout(self::experiment([], baseline: null));

        self::assertFalse($layout->rows);
        self::assertSame('lower is better', $layout->subtitle);
        self::assertSame(['sonnet', 'lsp', 'no-lsp', 'codex'], array_column($layout->items, 'name'));
        self::assertSame(['$2.84', '$3.70', '$4.12', '$4.45'], array_map(self::tight(...), array_column($layout->items, 'value')));
        self::assertSame([null, null, null, null], array_column($layout->items, 'chip'));
    }

    public function test_more_than_four_cards_show_the_best_four_and_count_the_rest(): void
    {
        $costs = ['a' => 1.0, 'b' => 2.0, 'c' => 3.0, 'd' => 4.0, 'e' => 5.0, 'f' => 6.0];

        $layout = $this->layout(self::experiment([], baseline: null, costs: $costs));

        self::assertSame(['a', 'b', 'c', 'd'], array_column($layout->items, 'name'));
        self::assertSame(2, $layout->more);
    }

    public function test_a_goal_key_that_is_not_a_metric_shows_the_cost_cards_cheapest_first_with_or_without_a_baseline(): void
    {
        foreach (['no-lsp', null] as $baseline) {
            $layout = $this->layout(self::experiment([], baseline: $baseline, problem: GoalState::UnknownMetric, goalMetric: new ExperimentMetric('typo', [], null)));

            self::assertFalse($layout->rows);
            self::assertSame('Cost per merged card', $layout->title);
            self::assertSame('lower is better', $layout->subtitle);
            self::assertSame(['sonnet', 'lsp', 'no-lsp', 'codex'], array_column($layout->items, 'name'));
        }
    }

    public function test_a_goal_with_no_direction_shows_the_goal_metric_in_the_order_of_the_page_with_no_colour(): void
    {
        $layout = $this->layout(self::experiment([], baseline: 'no-lsp', problem: GoalState::NoDirection, direction: null));

        self::assertFalse($layout->rows);
        self::assertSame('', $layout->subtitle);
        self::assertSame(['no-lsp', 'sonnet', 'lsp', 'codex'], array_column($layout->items, 'name'));
        self::assertSame([ExperimentResultTone::None], array_values(array_unique(array_column($layout->items, 'tone'), \SORT_REGULAR)));
    }

    public function test_a_result_with_no_headline_shows_the_cost_cards_of_its_variants(): void
    {
        $result = self::experiment([]);
        $layout = $this->layout(new PublicExperimentResult($result->computedAt, $result->experiment, null, $result->variants, $result->metrics));

        self::assertFalse($layout->rows);
        self::assertSame(['sonnet', 'lsp', 'no-lsp', 'codex'], array_column($layout->items, 'name'));
    }

    /** The currency format puts a space after the sign in this locale, so the tests compare without spaces. */
    private static function tight(string $text): string
    {
        return str_replace(["\u{00A0}", "\u{202F}", '$ '], ['', '', '$'], $text);
    }

    private function layout(PublicExperimentResult $result): ExperimentPreviewLayout
    {
        self::bootKernel();
        $layouts = self::getContainer()->get(ExperimentPreviewLayouts::class);
        self::assertInstanceOf(ExperimentPreviewLayouts::class, $layouts);

        return $layouts->build($result);
    }

    private static function comparison(string $variant, GoalState $state, float $point, Interval $change): ExperimentComparison
    {
        return new ExperimentComparison($variant, $state, new ExperimentArm($variant, $point, 6), true, $change, null, []);
    }

    /**
     * @param list<ExperimentComparison> $comparisons
     * @param array<string, float>|null  $costs
     */
    public static function experiment(array $comparisons, ?string $baseline = 'no-lsp', ?GoalState $problem = null, ?GoalDirection $direction = GoalDirection::Lower, ?ExperimentMetric $goalMetric = null, ?array $costs = null): PublicExperimentResult
    {
        $costs ??= self::COSTS;
        $cost = new ExperimentMetric('cost', array_map(static fn (float $point): Interval => new Interval($point, $point, $point), $costs), null, shape: new MetricShape(new MetricKey(Metric::Cost)));
        $headline = new ExperimentHeadline(
            new ExperimentGoal($goalMetric ?? $cost, $direction, $problem),
            $baseline,
            new Confidence(0.95),
            null === $baseline ? null : new ExperimentArm($baseline, $costs[$baseline] ?? null, 6),
            $comparisons,
            [],
        );
        $variants = array_map(static fn (string $name): ExperimentVariant => new ExperimentVariant($name, [], null, 6, 6, 6, null, null), array_keys($costs));
        usort($variants, static fn (ExperimentVariant $a, ExperimentVariant $b): int => (int) ('no-lsp' === $b->name) <=> (int) ('no-lsp' === $a->name));

        return new PublicExperimentResult(new \DateTimeImmutable('2026-11-08 10:00:00'), 'implement', $headline, $variants, ['cost' => $cost]);
    }
}
