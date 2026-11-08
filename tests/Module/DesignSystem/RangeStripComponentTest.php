<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

final class RangeStripComponentTest extends KernelTestCase
{
    private function render(string $template): Crawler
    {
        self::bootKernel();

        return new Crawler(static::getContainer()->get(Environment::class)->createTemplate($template)->render());
    }

    public function test_a_range_draws_a_band_a_dot_and_a_dashed_baseline_in_percent_and_hides_from_screen_readers(): void
    {
        $strip = $this->render('<twig:Ds:RangeStrip :low="8" :point="24.5" :high="42" :baseline="62" />')->filter('svg.lp-range-strip');

        self::assertCount(1, $strip);
        self::assertSame('true', $strip->attr('aria-hidden'));
        self::assertSame('8%', $strip->filter('rect.lp-range-strip__band')->attr('x'));
        self::assertSame('34%', $strip->filter('rect.lp-range-strip__band')->attr('width'));
        self::assertSame('24.5%', $strip->filter('circle.lp-range-strip__point')->attr('cx'));
        self::assertSame('62%', $strip->filter('line.lp-range-strip__baseline')->attr('x1'));
        self::assertCount(0, $strip->filter('text'));
        self::assertStringNotContainsString('lp-range-strip--labelled', (string) $strip->attr('class'));
    }

    public function test_a_range_of_zero_width_draws_no_band(): void
    {
        $strip = $this->render('<twig:Ds:RangeStrip :low="40" :point="40" :high="40" :baseline="62" />')->filter('svg.lp-range-strip');

        self::assertCount(0, $strip->filter('rect'));
        self::assertCount(1, $strip->filter('circle'));
    }

    public function test_a_point_on_the_baseline_and_a_label_under_the_line(): void
    {
        $strip = $this->render('<twig:Ds:RangeStrip :low="50" :point="50" :high="50" :baseline="50" label="baseline" data-x />')->filter('svg.lp-range-strip');

        self::assertSame('50%', $strip->filter('circle')->attr('cx'));
        self::assertSame('50%', $strip->filter('line.lp-range-strip__baseline')->attr('x1'));
        self::assertSame('baseline', trim($strip->filter('text.lp-range-strip__label')->text()));
        self::assertSame('50%', $strip->filter('text')->attr('x'));
        self::assertStringContainsString('lp-range-strip--labelled', (string) $strip->attr('class'));
        self::assertNotNull($strip->attr('data-x'));
    }

    public function test_without_a_baseline_the_strip_draws_no_line(): void
    {
        $strip = $this->render('<twig:Ds:RangeStrip :low="10" :point="20" :high="30" />')->filter('svg.lp-range-strip');

        self::assertCount(0, $strip->filter('line.lp-range-strip__baseline'));
    }

    public function test_the_strip_draws_a_full_width_track_first(): void
    {
        $strip = $this->render('<twig:Ds:RangeStrip :low="10" :point="20" :high="30" />')->filter('svg.lp-range-strip');

        self::assertCount(1, $strip->filter('line.lp-range-strip__track'));
        self::assertSame('0', $strip->filter('line.lp-range-strip__track')->attr('x1'));
        self::assertSame('100%', $strip->filter('line.lp-range-strip__track')->attr('x2'));
        self::assertStringContainsString('lp-range-strip__track', (string) $strip->children()->first()->attr('class'));
    }
}
