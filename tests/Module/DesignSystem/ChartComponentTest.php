<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricChangeKind;
use App\Module\Bridge\Metric\MetricKey;
use App\Module\Bridge\Metric\MetricShape;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\DesignSystem\Catalog;
use App\Module\Insights\View\BarLayout;
use App\Module\Insights\View\DonutLayout;
use App\Module\Insights\View\LineLayout;
use App\Module\Insights\View\MetricChartSpecimen;
use App\Module\Insights\View\MetricChartType;
use App\Module\Insights\View\MetricValueFormatter;
use App\Module\Insights\View\NumberLayout;
use App\Tests\Support\ChartSeries;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

final class ChartComponentTest extends KernelTestCase
{
    /** @param array<string, mixed> $context */
    private function render(string $template, array $context = []): string
    {
        self::bootKernel();

        return static::getContainer()->get(Environment::class)->createTemplate($template)->render($context);
    }

    /** @param list<string> $labels */
    private function chart(LineLayout|BarLayout|DonutLayout|NumberLayout $layout, array $labels): Crawler
    {
        return new Crawler($this->render('<twig:Ds:Chart :layout="layout" :labels="labels" id="t" />', ['layout' => $layout, 'labels' => $labels]));
    }

    /** @return iterable<string, array{MetricChartType}> */
    public static function types(): iterable
    {
        foreach (MetricChartType::cases() as $type) {
            yield $type->value => [$type];
        }
    }

    #[DataProvider('types')]
    public function test_every_type_draws_with_a_table_except_the_number(MetricChartType $type): void
    {
        $specimen = MetricChartSpecimen::of($type);
        $crawler = $this->chart($specimen['layout'], $specimen['labels']);

        self::assertCount(1, $crawler->filter('figure.lp-chart--'.$type->value));
        self::assertCount(MetricChartType::Number === $type ? 0 : 1, $crawler->filter('details.lp-chart__table:not([open]) > summary + * .lp-data-table, details.lp-chart__table:not([open]) > .lp-table-cue .lp-data-table'));
        self::assertCount(MetricChartType::Number === $type ? 0 : 1, $crawler->filter('svg.lp-chart__svg'));
    }

    #[DataProvider('types')]
    public function test_each_hover_target_names_one_template(MetricChartType $type): void
    {
        $specimen = MetricChartSpecimen::of($type);
        $crawler = $this->chart($specimen['layout'], $specimen['labels']);

        $templates = $crawler->filter('template')->each(static fn (Crawler $template): string => (string) $template->attr('id'));
        $targets = $crawler->filter('.lp-chart__target[aria-describedby]')->each(static fn (Crawler $target): string => (string) $target->attr('aria-describedby'));
        $layout = $specimen['layout'];
        $expected = $layout instanceof NumberLayout ? 0 : \count($layout->hoverTargets);

        self::assertCount($expected, $templates);
        self::assertSame($templates, $targets);
        self::assertSame($templates, array_unique($templates));
        self::assertSame(array_fill(0, $expected, '0'), $crawler->filter('.lp-chart__target[aria-describedby]')->each(static fn (Crawler $target): string => (string) $target->attr('tabindex')));
    }

    public function test_a_number_chart_has_no_hover_controller(): void
    {
        $specimen = MetricChartSpecimen::of(MetricChartType::Number);
        $crawler = $this->chart($specimen['layout'], $specimen['labels']);

        self::assertCount(0, $crawler->filter('[data-controller]'));
        self::assertCount(3, $crawler->filter('.lp-chart__figure'));
        self::assertGreaterThan(0, $crawler->filter('.lp-chart__figure .lp-chart__change')->count());
    }

    public function test_a_line_chart_hands_the_surface_and_the_keys_to_the_hover_controller(): void
    {
        $specimen = MetricChartSpecimen::of(MetricChartType::Line);
        $crawler = $this->chart($specimen['layout'], $specimen['labels']);

        self::assertSame('chart-hover', $crawler->filter('figure')->attr('data-controller'));
        self::assertStringContainsString('keydown.esc->chart-hover#hide', (string) $crawler->filter('figure')->attr('data-action'));
        self::assertCount(1, $crawler->filter('[data-chart-hover-target="surface"]'));
    }

    public function test_the_hover_card_holds_the_dates_the_rows_and_the_previous_period(): void
    {
        $specimen = MetricChartSpecimen::of(MetricChartType::Line);
        $html = $this->render('<twig:Ds:Chart :layout="layout" :labels="labels" id="t" />', $specimen);
        $card = new Crawler($html)->filter('template#t-hover-1')->html();

        self::assertStringContainsString('Oct 12 – Oct 18', $card);
        self::assertStringContainsString('product-design', $card);
        self::assertStringContainsString('$0.14', $card);
        self::assertStringContainsString('Previous period: $0.15', $card);
    }

    public function test_stacked_bars_show_a_total_and_shares_in_the_card_and_the_table(): void
    {
        $specimen = MetricChartSpecimen::of(MetricChartType::StackedBars);
        $crawler = $this->chart($specimen['layout'], $specimen['labels']);

        self::assertCount(4, $crawler->filter('.lp-chart__card-total'));
        self::assertCount(3, $crawler->filter('.lp-data-table [role="columnheader"]'));
        self::assertStringContainsString('Share', $crawler->filter('.lp-data-table__header')->text());
    }

    public function test_a_group_name_is_escaped_everywhere_it_prints(): void
    {
        $series = [
            ChartSeries::of('<script>alert(1)</script>', ['2026-09-14' => 1.0, '2026-09-21' => 2.0], total: 3.0),
            ChartSeries::of('plain', ['2026-09-14' => 2.0, '2026-09-21' => 1.0], total: 3.0),
        ];
        $shape = new MetricShape(new MetricKey(Metric::Runs));
        $formatter = new MetricValueFormatter('en');
        $labels = ['<script>alert(1)</script>', 'plain'];
        $layouts = [
            LineLayout::build($shape, MetricStatistic::Sum, MetricBucket::Week, $series, $formatter, true),
            BarLayout::build(MetricChartType::StackedBars, $shape, MetricStatistic::Sum, MetricBucket::Week, $series, $formatter, true),
            DonutLayout::build($shape, MetricStatistic::Sum, $series, $formatter),
            NumberLayout::build($shape, MetricStatistic::Sum, $series, $formatter),
        ];

        foreach ($layouts as $layout) {
            self::assertNotNull($layout);
            $html = $this->render('<twig:Ds:Chart :layout="layout" :labels="labels" id="t" />', ['layout' => $layout, 'labels' => $labels]);
            self::assertStringNotContainsString('<script>', $html);
            self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        }
    }

    public function test_the_legend_names_the_previous_period_only_on_a_line_chart_that_compares(): void
    {
        $line = MetricChartSpecimen::of(MetricChartType::Line);
        self::assertStringContainsString('Previous period', $this->chart($line['layout'], $line['labels'])->filter('.lp-chart__legend')->text());
        self::assertCount(3, $this->chart($line['layout'], $line['labels'])->filter('.lp-chart__line--previous'));

        $shape = new MetricShape(new MetricKey(Metric::Cost));
        $series = [ChartSeries::of('a', ['2026-09-14' => 1.0, '2026-09-21' => 2.0]), ChartSeries::of('b', ['2026-09-14' => 2.0, '2026-09-21' => 1.0])];
        $plain = LineLayout::build($shape, MetricStatistic::Median, MetricBucket::Week, $series, new MetricValueFormatter('en'), false);
        self::assertNotNull($plain);
        $crawler = $this->chart($plain, ['a', 'b']);
        self::assertStringNotContainsString('Previous period', $crawler->filter('.lp-chart__legend')->text());
        self::assertCount(0, $crawler->filter('.lp-chart__line--previous'));
        self::assertCount(0, $crawler->filter('.lp-chart__dash'));
    }

    public function test_a_chart_with_one_line_and_no_compare_has_no_legend(): void
    {
        $series = [ChartSeries::of('a', ['2026-09-14' => 1.0, '2026-09-21' => 2.0])];
        $layout = LineLayout::build(new MetricShape(new MetricKey(Metric::Cost)), MetricStatistic::Median, MetricBucket::Week, $series, new MetricValueFormatter('en'), false);
        self::assertNotNull($layout);

        self::assertCount(0, $this->chart($layout, ['a'])->filter('.lp-chart__legend'));
    }

    public function test_every_change_is_one_neutral_colour_with_an_arrow_and_a_sign_chip(): void
    {
        $specimen = MetricChartSpecimen::of(MetricChartType::Line);
        $crawler = $this->chart($specimen['layout'], $specimen['labels']);

        self::assertSame(
            ['lp-chart__change', 'lp-chart__change', 'lp-chart__change'],
            $crawler->filter('.lp-chart__change')->each(static fn (Crawler $change): string => (string) $change->attr('class')),
        );
        self::assertSame(['↓', '↑', '↓'], $crawler->filter('.lp-chart__change [aria-hidden]')->each(static fn (Crawler $arrow): string => $arrow->text()));
        self::assertSame(
            ['Not clear yet', 'Clear', 'Not enough cards'],
            $crawler->filter('.lp-chart__sign')->each(static fn (Crawler $chip): string => $chip->text()),
        );
    }

    #[DataProvider('signedTypes')]
    public function test_a_sign_chip_shows_the_likely_range_and_card_count_of_each_period_on_hover_and_focus(MetricChartType $type): void
    {
        $specimen = MetricChartSpecimen::of($type);
        $crawler = $this->chart($specimen['layout'], $specimen['labels']);

        $anchors = $crawler->filter('.lp-chart__sign-anchor');
        self::assertCount(3, $anchors);
        $anchors->each(static function (Crawler $anchor): void {
            self::assertSame('0', $anchor->attr('tabindex'));
            self::assertSame($anchor->attr('aria-describedby'), $anchor->filter('[role="tooltip"]')->attr('id'));
        });
        self::assertCount(3, array_unique($anchors->each(static fn (Crawler $anchor): string => (string) $anchor->attr('aria-describedby'))));

        $clear = $anchors->eq(1)->filter('.lp-chart__sign-detail')->text();
        self::assertStringContainsString('This period', $clear);
        self::assertStringContainsString('Likely range $0.20 to $0.24', $clear);
        self::assertStringContainsString('12 cards', $clear);
        self::assertStringContainsString('Previous period', $clear);
        self::assertStringContainsString('11 cards', $clear);

        $thin = $anchors->eq(2)->filter('.lp-chart__sign-detail')->text();
        self::assertStringNotContainsString('Likely range', $thin);
        self::assertStringContainsString('3 cards', $thin);
        self::assertStringContainsString('4 cards', $thin);
    }

    /** @return iterable<string, array{MetricChartType}> */
    public static function signedTypes(): iterable
    {
        yield 'table' => [MetricChartType::Line];
        yield 'number' => [MetricChartType::Number];
    }

    public function test_a_change_of_rates_reads_in_points(): void
    {
        $series = [ChartSeries::total('a', 0.5, ChartSeries::comparison(0.4, 0.1, MetricChangeKind::Points, null))];
        $layout = NumberLayout::build(new MetricShape(new MetricKey(Metric::StopRate)), MetricStatistic::Mean, $series, new MetricValueFormatter('en'));
        self::assertNotNull($layout);

        self::assertStringContainsString('10 pts', $this->chart($layout, ['a'])->filter('.lp-chart__change')->text());
    }

    public function test_a_compare_with_an_empty_previous_window_draws_no_previous_legend_entry(): void
    {
        $series = [
            ChartSeries::of('a', ['2026-09-14' => 1.0, '2026-09-21' => 2.0], comparison: ChartSeries::comparison()),
            ChartSeries::of('b', ['2026-09-14' => 2.0, '2026-09-21' => 1.0], comparison: ChartSeries::comparison()),
        ];
        $layout = LineLayout::build(new MetricShape(new MetricKey(Metric::Cost)), MetricStatistic::Median, MetricBucket::Week, $series, new MetricValueFormatter('en'), false);
        self::assertNotNull($layout);
        self::assertTrue($layout->comparing);

        $crawler = $this->chart($layout, ['a', 'b']);

        self::assertStringNotContainsString('Previous period', $crawler->filter('.lp-chart__legend')->text());
        self::assertCount(0, $crawler->filter('.lp-chart__dash'));
        self::assertCount(0, $crawler->filter('.lp-chart__line--previous'));
    }

    /** @return iterable<string, array{list<int|float|null>}> */
    public static function emptyDonuts(): iterable
    {
        yield 'zero totals' => [[0, 0]];
        yield 'unknown totals' => [[null, null]];
    }

    /** @param list<int|float|null> $totals */
    #[DataProvider('emptyDonuts')]
    public function test_a_donut_with_nothing_to_show_draws_an_empty_ring_and_a_note(array $totals): void
    {
        $series = [ChartSeries::total('a', $totals[0]), ChartSeries::total('b', $totals[1])];
        $layout = DonutLayout::build(new MetricShape(new MetricKey(Metric::Runs)), MetricStatistic::Sum, $series, new MetricValueFormatter('en'));
        self::assertNotNull($layout);

        $crawler = $this->chart($layout, ['a', 'b']);

        self::assertCount(1, $crawler->filter('.lp-chart__ring-empty'));
        self::assertCount(0, $crawler->filter('.lp-chart__slice'));
        self::assertCount(0, $crawler->filter('.lp-chart__donut-total'));
        self::assertCount(0, $crawler->filter('template'));
        self::assertCount(0, $crawler->filter('.lp-chart__target[aria-describedby]'));
        self::assertSame('No values to show', trim($crawler->filter('.lp-chart__donut-note')->text()));
    }

    public function test_a_bucket_with_no_rows_has_no_hover_target_and_no_template(): void
    {
        $series = [ChartSeries::of('a', ['2026-09-14' => 1.0, '2026-09-21' => null, '2026-09-28' => 3.0])];
        $layout = LineLayout::build(new MetricShape(new MetricKey(Metric::Cost)), MetricStatistic::Median, MetricBucket::Week, $series, new MetricValueFormatter('en'), false);
        self::assertNotNull($layout);
        self::assertCount(3, $layout->hoverTargets);

        $crawler = $this->chart($layout, ['a']);

        self::assertCount(2, $crawler->filter('template'));
        self::assertCount(2, $crawler->filter('.lp-chart__target[aria-describedby]'));
    }

    #[DataProvider('types')]
    public function test_a_hover_target_has_no_browser_tooltip_and_a_label_with_no_trailing_colon(MetricChartType $type): void
    {
        $specimen = MetricChartSpecimen::of($type);
        $crawler = $this->chart($specimen['layout'], $specimen['labels']);

        self::assertCount(0, $crawler->filter('.lp-chart__target[aria-describedby] title'));
        $labels = $crawler->filter('.lp-chart__target[aria-describedby]')->each(static fn (Crawler $target): string => (string) $target->attr('aria-label'));
        foreach ($labels as $label) {
            self::assertNotSame('', $label);
            self::assertStringEndsNotWith(':', $label);
        }
    }

    public function test_bars_that_do_not_add_up_have_no_share_column(): void
    {
        $specimen = MetricChartSpecimen::of(MetricChartType::Bars);
        $header = $this->chart($specimen['layout'], $specimen['labels'])->filter('.lp-data-table__header')->text();

        self::assertStringNotContainsString('Share', $header);
        self::assertStringContainsString('Value', $header);
    }

    public function test_a_chart_that_does_not_compare_has_no_change_column(): void
    {
        $specimen = MetricChartSpecimen::of(MetricChartType::Bars);
        $crawler = $this->chart($specimen['layout'], $specimen['labels']);

        self::assertStringNotContainsString('Change', $crawler->filter('.lp-data-table__header')->text());
        self::assertCount(0, $crawler->filter('.lp-chart__change'));
    }

    public function test_a_null_sign_prints_no_chip(): void
    {
        $series = [ChartSeries::total('a', 0.5, ChartSeries::comparison(0.4, 0.25, MetricChangeKind::Relative, null))];
        $layout = NumberLayout::build(new MetricShape(new MetricKey(Metric::Cost)), MetricStatistic::Sum, $series, new MetricValueFormatter('en'));
        self::assertNotNull($layout);

        $crawler = $this->chart($layout, ['a']);

        self::assertCount(1, $crawler->filter('.lp-chart__change'));
        self::assertCount(0, $crawler->filter('.lp-chart__sign'));
    }

    public function test_two_charts_with_no_id_get_different_hover_ids(): void
    {
        $specimen = MetricChartSpecimen::of(MetricChartType::Line);
        $html = $this->render('<twig:Ds:Chart :layout="layout" :labels="labels" /><twig:Ds:Chart :layout="layout" :labels="labels" />', $specimen);

        $ids = new Crawler($html)->filter('template')->each(static fn (Crawler $template): string => (string) $template->attr('id'));

        self::assertCount(8, $ids);
        self::assertSame($ids, array_unique($ids));
    }

    public function test_the_styleguide_specimen_draws_every_catalog_variant(): void
    {
        $variants = null;
        foreach (new Catalog()->entries() as $entry) {
            if ('Chart' === $entry->name) {
                $variants = $entry->variants;
            }
        }

        self::assertSame(array_map(static fn (MetricChartType $type): string => $type->value, MetricChartType::cases()), $variants);
        foreach ($variants as $variant) {
            $html = $this->render("{% include '@DesignSystem/specimen/chart.html.twig' with {variant: variant} only %}", ['variant' => $variant]);
            self::assertCount(1, new Crawler($html)->filter('figure.lp-chart--'.$variant));
        }
    }
}
