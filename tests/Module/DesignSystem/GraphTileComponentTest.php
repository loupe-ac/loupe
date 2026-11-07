<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

final class GraphTileComponentTest extends KernelTestCase
{
    private function render(string $template): string
    {
        self::bootKernel();

        return static::getContainer()->get(Environment::class)->createTemplate($template)->render([]);
    }

    public function test_a_public_tile_ends_its_settings_line_with_the_lime_tag(): void
    {
        $crawler = new Crawler($this->render('<twig:Ds:GraphTile name="Cost" settings="Cost · Sum" :public="true" publicLabel="Public" />'));

        $tag = $crawler->filter('.lp-graph-tile__settings [data-graph-public] .lp-tag.lp-tag--lime');
        self::assertCount(1, $tag);
        self::assertSame('Public', trim($tag->text()));
    }

    public function test_a_tile_that_is_not_public_keeps_an_empty_hook_for_a_stream(): void
    {
        $crawler = new Crawler($this->render('<twig:Ds:GraphTile name="Cost" settings="Cost · Sum" />'));

        self::assertCount(1, $crawler->filter('.lp-graph-tile__settings [data-graph-public]'));
        self::assertCount(0, $crawler->filter('.lp-tag'));
    }
}
