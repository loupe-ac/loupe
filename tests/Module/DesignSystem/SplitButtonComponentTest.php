<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

final class SplitButtonComponentTest extends KernelTestCase
{
    private function render(string $template): Crawler
    {
        self::bootKernel();

        return new Crawler(static::getContainer()->get(Environment::class)->createTemplate($template)->render());
    }

    public function test_a_main_button_and_rows_draw_a_chevron_toggle_named_by_its_label(): void
    {
        $crawler = $this->render('<twig:Ds:SplitButton id="s1" toggleLabel="Other actions" menuSize="lg" class="page-hook" data-x="1"><twig:block name="main"><twig:Ds:Button variant="primary" size="sm">Go</twig:Ds:Button></twig:block><twig:block name="content"><twig:Ds:MenuItem role="menuitem">Row</twig:Ds:MenuItem></twig:block></twig:Ds:SplitButton>');

        $root = $crawler->filter('.lp-split-button');
        self::assertSame('lp-menu lp-menu--lg lp-split-button page-hook', $root->attr('class'));
        self::assertStringContainsString('split-button', (string) $root->attr('data-controller'));
        self::assertSame('1', $root->attr('data-x'));
        $toggle = $root->filter('.lp-split-button__toggle');
        self::assertSame('lp-btn lp-btn--primary lp-btn--sm lp-split-button__toggle', $toggle->attr('class'));
        self::assertSame('Other actions', $toggle->attr('aria-label'));
        self::assertSame('s1', $toggle->attr('aria-controls'));
        self::assertSame('trigger', $toggle->attr('data-split-button-target'));
        self::assertSame('', trim($toggle->text()));
        self::assertSame('Go', $root->filter('.lp-btn')->first()->text());
        $panel = $root->filter('#s1');
        self::assertSame('menu', $panel->attr('role'));
        self::assertSame('panel', $panel->attr('data-split-button-target'));
        self::assertSame('Row', $panel->filter('[role="menuitem"]')->text());
    }

    public function test_without_a_main_button_the_toggle_is_a_whole_button_with_its_label(): void
    {
        $toggle = $this->render('<twig:Ds:SplitButton id="s2" toggleLabel="Other actions"><twig:block name="content"><twig:Ds:MenuItem role="menuitem">Row</twig:Ds:MenuItem></twig:block></twig:Ds:SplitButton>')->filter('.lp-split-button__toggle');

        self::assertSame('lp-btn lp-btn--primary lp-btn--sm lp-split-button__toggle lp-split-button__toggle--alone', $toggle->attr('class'));
        self::assertNull($toggle->attr('aria-label'));
        self::assertSame('Other actions', trim($toggle->text()));
    }

    public function test_without_rows_the_main_button_stands_alone(): void
    {
        $root = $this->render('<twig:Ds:SplitButton id="s3" toggleLabel="Other actions"><twig:block name="main"><twig:Ds:Button>Go</twig:Ds:Button></twig:block></twig:Ds:SplitButton>')->filter('.lp-split-button');

        self::assertCount(0, $root->filter('.lp-split-button__toggle'));
        self::assertCount(1, $root->filter('.lp-btn'));
    }
}
