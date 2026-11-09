<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Preview;

use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricKey;
use App\Module\Bridge\Metric\MetricShape;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Insights\Preview\PreviewPalette;
use App\Module\Insights\View\ChartBox;
use App\Module\Insights\View\LineLayout;
use App\Module\Insights\View\MetricValueFormatter;
use App\Tests\Support\ChartSeries;
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

    public function test_a_line_graph_draws_the_open_segment_as_round_dots_in_the_colour_of_the_series(): void
    {
        $series = [ChartSeries::of(null, ['2026-10-26' => 1.0, '2026-11-02' => 2.0, '2026-11-09' => 3.0])];
        $build = static fn (string $at): ?LineLayout => LineLayout::build(new MetricShape(new MetricKey(Metric::Cost)), MetricStatistic::Median, MetricBucket::Week, $series, new MetricValueFormatter('en'), false, new \DateTimeImmutable($at), ChartBox::preview());

        $open = $this->draw($build('2026-11-09 01:54 UTC'), [0 => 'Cost']);
        $closed = $this->draw($build('2026-11-16 00:00 UTC'), [0 => 'Cost']);

        self::assertNotFalse(simplexml_load_string($open));
        self::assertSame(1, substr_count($open, 'stroke-dasharray="1 9"'));
        self::assertSame(0, substr_count($closed, 'stroke-dasharray'));
    }

    /**
     * @param array<string, mixed>|LineLayout $layout
     * @param array<int, string>              $labels
     */
    private function draw(array|LineLayout|null $layout, array $labels): string
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
