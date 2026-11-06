<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

final class ValueRowComponentTest extends KernelTestCase
{
    private function render(string $template): Crawler
    {
        self::bootKernel();

        return new Crawler(static::getContainer()->get(Environment::class)->createTemplate($template)->render());
    }

    public function test_a_control_row_draws_the_label_the_control_the_help_mark_and_the_meta_line(): void
    {
        $row = $this->render('<twig:Ds:ValueRow id="r" label="Name" help="Why" helpLabel="Help" helpId="tip"><input id="c" aria-describedby="tip"><twig:block name="meta">Set by Loupe</twig:block></twig:Ds:ValueRow>')->filter('#r');

        self::assertCount(1, $row->filter('.lp-value-row'));
        self::assertCount(0, $row->filter('.lp-value-row--read-only'));
        self::assertSame('Name', $row->filter('.lp-value-row__label')->text());
        self::assertCount(1, $row->filter('.lp-value-row__control input#c'));
        self::assertCount(0, $row->filter('.lp-value-row__value'));
        self::assertSame('Help', $row->filter('.lp-value-row__help [role="img"]')->attr('aria-label'));
        self::assertSame('tip', $row->filter('.lp-value-row__help [role="img"]')->attr('aria-describedby'));
        self::assertSame('Why', trim($row->filter('.lp-value-row__help [role="tooltip"]#tip')->text()));
        self::assertSame('Set by Loupe', trim($row->filter('.lp-value-row__meta')->text()));
    }

    public function test_a_read_only_row_draws_the_value_in_place_of_a_control(): void
    {
        $row = $this->render('<twig:Ds:ValueRow label="Reviewer" text="Riley" />')->filter('.lp-value-row');

        self::assertCount(1, $row->filter('.lp-value-row--read-only'));
        self::assertSame('Reviewer', $row->filter('.lp-value-row__line > .lp-value-row__label')->text());
        self::assertSame('Riley', $row->filter('.lp-value-row__line .lp-value-row__value')->text());
        self::assertCount(0, $row->filter('.lp-value-row__control'));
    }

    public function test_without_help_or_meta_those_parts_are_left_out(): void
    {
        $row = $this->render('<twig:Ds:ValueRow label="Reviewer" text="Riley" data-x />')->filter('.lp-value-row');

        self::assertCount(0, $row->filter('.lp-value-row__help'));
        self::assertCount(0, $row->filter('.lp-value-row__meta'));
        self::assertNotNull($row->attr('data-x'));
    }

    public function test_the_footer_block_follows_the_meta_line(): void
    {
        $row = $this->render('<twig:Ds:ValueRow label="L" text="V"><twig:block name="meta">M</twig:block><twig:block name="footer"><b id="f">F</b></twig:block></twig:Ds:ValueRow>')->filter('.lp-value-row');

        self::assertCount(1, $row->filter('.lp-value-row__meta + #f'));
    }
}
