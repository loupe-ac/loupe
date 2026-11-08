<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Preview;

use App\Module\Bridge\Experiment\ExperimentArm;
use App\Module\Bridge\Experiment\ExperimentComparison;
use App\Module\Bridge\Experiment\GoalDirection;
use App\Module\Bridge\Experiment\GoalState;
use App\Module\Bridge\Experiment\Interval;
use App\Module\Insights\Preview\ExperimentPreviewLayouts;
use App\Module\Insights\Preview\PreviewPalette;
use App\Module\Insights\View\PublicExperimentResult;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class ExperimentSvgTemplateTest extends KernelTestCase
{
    public function test_rows_draw_the_baseline_the_key_the_brand_and_the_more_line(): void
    {
        $comparisons = [];
        foreach (range(1, 6) as $i) {
            $comparisons[] = new ExperimentComparison('variant-'.$i, GoalState::Clear, new ExperimentArm('variant-'.$i, 3.0, 6), true, new Interval(-0.4 + $i / 20, -0.1, -0.2 - $i / 100), null, []);
        }

        $svg = $this->draw(ExperimentPreviewLayoutsTest::experiment($comparisons));

        self::assertNotFalse(simplexml_load_string($svg));
        self::assertStringContainsString('>baseline<', $svg);
        self::assertStringContainsString('likely range', $svg);
        self::assertStringContainsString('+2 more variants', $svg);
        self::assertStringContainsString('Made with Loupe', $svg);
        self::assertSame(4, substr_count($svg, 'r="10"'));
        self::assertSame(4, substr_count($svg, 'height="32" rx="8"'));
        self::assertStringNotContainsString('href', $svg);
        self::assertStringNotContainsString('foreignObject', $svg);
    }

    public function test_a_better_change_is_green_and_a_worse_change_is_red(): void
    {
        $better = new ExperimentComparison('cheap', GoalState::Clear, new ExperimentArm('cheap', 2.0, 6), true, new Interval(-0.4, -0.2, -0.3), null, []);
        $worse = new ExperimentComparison('dear', GoalState::NotClear, new ExperimentArm('dear', 5.0, 6), true, new Interval(0.1, 0.3, 0.2), null, []);

        $svg = $this->draw(ExperimentPreviewLayoutsTest::experiment([$worse, $better]));

        self::assertSame(1, preg_match('/fill="'.PreviewPalette::STATUS_OK_DEEP.'">−30%</', $svg));
        self::assertSame(1, preg_match('/fill="'.PreviewPalette::STATUS_DANGER_DEEP.'">\+20%</', $svg));
    }

    public function test_markup_in_a_variant_name_is_escaped_and_a_long_name_is_cut(): void
    {
        $name = '<script>alert(1)</script>-and-a-very-long-variant-name';
        $row = new ExperimentComparison($name, GoalState::Clear, new ExperimentArm($name, 2.0, 6), true, new Interval(-0.4, -0.2, -0.3), null, []);

        $svg = $this->draw(ExperimentPreviewLayoutsTest::experiment([$row]));

        self::assertNotFalse(simplexml_load_string($svg));
        self::assertStringNotContainsString('<script>', $svg);
        self::assertStringContainsString('&lt;script&gt;', $svg);
        self::assertStringNotContainsString('very-long', $svg);
    }

    public function test_cards_draw_each_value_and_the_more_line_and_no_baseline_or_key(): void
    {
        $costs = ['a' => 1.0, 'b' => 2.0, 'c' => 3.0, 'd' => 4.0, 'e' => 5.0, 'f' => 6.0];

        $svg = $this->draw(ExperimentPreviewLayoutsTest::experiment([], baseline: null, costs: $costs));

        self::assertNotFalse(simplexml_load_string($svg));
        self::assertSame(4, substr_count($svg, 'rx="16"'));
        self::assertStringContainsString('+2 more variants', $svg);
        self::assertStringNotContainsString('>baseline<', $svg);
        self::assertStringNotContainsString('likely range', $svg);
        self::assertStringContainsString('lower is better', $svg);
        self::assertStringContainsString('Made with Loupe', $svg);
    }

    public function test_cards_with_a_goal_that_names_no_direction_print_no_better_line(): void
    {
        $svg = $this->draw(ExperimentPreviewLayoutsTest::experiment([], problem: GoalState::NoDirection, direction: null));

        self::assertNotFalse(simplexml_load_string($svg));
        self::assertStringNotContainsString('is better', $svg);
        self::assertSame(4, substr_count($svg, 'rx="16"'));
    }

    public function test_a_result_that_draws_no_item_still_draws_a_well_formed_image(): void
    {
        $result = ExperimentPreviewLayoutsTest::experiment([], baseline: null, costs: []);

        $svg = $this->draw($result);

        self::assertNotFalse(simplexml_load_string($svg));
        self::assertStringContainsString('Made with Loupe', $svg);
        self::assertSame(GoalDirection::Lower, $result->headline?->goal->direction);
    }

    private function draw(PublicExperimentResult $result): string
    {
        self::bootKernel();
        $twig = self::getContainer()->get(Environment::class);
        $layouts = self::getContainer()->get(ExperimentPreviewLayouts::class);
        self::assertInstanceOf(Environment::class, $twig);
        self::assertInstanceOf(ExperimentPreviewLayouts::class, $layouts);

        return $twig->render('@Insights/public/preview/experiment.svg.twig', ['layout' => $layouts->build($result), 'palette' => PreviewPalette::forTemplate()]);
    }
}
