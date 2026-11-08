<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Preview;

use App\Module\Insights\Preview\PreviewPalette;
use App\Module\Insights\View\ChartBox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class GraphSvgTemplateTest extends KernelTestCase
{
    public function test_a_number_graph_draws_every_figure_and_every_legend_label(): void
    {
        $series = [];
        $figures = [];
        $labels = [];
        foreach (range(0, 8) as $i) {
            $series[] = ['position' => $i, 'slot' => $i + 1];
            $figures[] = ['series' => ['position' => $i, 'slot' => $i + 1], 'value' => '$'.$i.'.00', 'change' => null];
            $labels[$i] = 'model-'.$i;
        }

        $svg = $this->draw(['figures' => $figures, 'series' => $series, 'hasLegend' => true], $labels);

        self::assertNotFalse(simplexml_load_string($svg));
        foreach ($labels as $label) {
            self::assertStringContainsString('>'.$label.'<', $svg);
        }
        self::assertSame(9, substr_count($svg, 'rx="16"'));
    }

    public function test_a_bar_legend_of_long_names_keeps_every_label_inside_the_image(): void
    {
        $series = [];
        $labels = [];
        foreach (range(0, 11) as $i) {
            $series[] = ['position' => $i, 'slot' => $i + 1];
            $labels[$i] = 'claude-model-with-a-long-name-'.$i;
        }

        $svg = $this->draw(['buckets' => [], 'series' => $series, 'hasLegend' => true, 'yTicks' => [], 'xTicks' => [], 'axisMetric' => null, 'axisStatistic' => null, 'longSpan' => false, 'bucket' => ['value' => 'day'], 'moneyDecimals' => 2], $labels);

        self::assertNotFalse(simplexml_load_string($svg));
        self::assertSame(12, substr_count($svg, 'width="14" height="14"'));
    }

    public function test_a_bar_legend_of_eighty_series_draws_readable_labels_and_a_more_key(): void
    {
        $series = [];
        $labels = [];
        foreach (range(0, 79) as $i) {
            $series[] = ['position' => $i, 'slot' => $i + 1];
            $labels[$i] = 'Series '.$i;
        }

        $svg = $this->draw(['buckets' => [], 'series' => $series, 'hasLegend' => true, 'yTicks' => [], 'xTicks' => [], 'axisMetric' => null, 'axisStatistic' => null, 'longSpan' => false, 'bucket' => ['value' => 'day'], 'moneyDecimals' => 2], $labels);

        self::assertNotFalse(simplexml_load_string($svg));
        self::assertSame(0, preg_match('/font-size="(?:\d[."]|1[0-5])/', $svg));
        self::assertSame(1, preg_match('/>\+(\d+) more</', $svg, $more));
        self::assertSame(80, substr_count($svg, 'width="14" height="14"') + (int) ($more[1] ?? 0));
    }

    /**
     * @param array<string, mixed> $layout
     * @param array<int, string>   $labels
     */
    private function draw(array $layout, array $labels): string
    {
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);

        return $twig->render('@Insights/public/preview/graph.svg.twig', [
            'state' => 'chart',
            'view' => ['layout' => $layout, 'labels' => $labels],
            'legend' => true,
            'box' => ChartBox::preview(),
            'palette' => PreviewPalette::forTemplate(),
        ]);
    }
}
