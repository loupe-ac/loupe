<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

final class FilterBarComponentTest extends KernelTestCase
{
    private function render(string $template): string
    {
        self::bootKernel();

        return static::getContainer()->get(Environment::class)->createTemplate($template)->render();
    }

    public function test_a_bar_with_an_action_holds_an_autosearch_form(): void
    {
        $html = $this->render('<twig:Ds:FilterBar action="/list" :formAttributes="{\'data-metrics-form\': true}" />');

        self::assertStringContainsString('<div class="lp-list-filters">', $html);
        self::assertStringContainsString('<form method="get" action="/list" data-controller="autosearch" class="lp-filter-form" data-metrics-form>', $html);
    }

    public function test_a_bar_without_an_action_draws_no_form(): void
    {
        $html = $this->render('<twig:Ds:FilterBar class="extra" count="3 items"><nav>toggle</nav></twig:Ds:FilterBar>');

        self::assertStringContainsString('class="lp-list-filters extra"', $html);
        self::assertStringNotContainsString('<form', $html);
        self::assertStringContainsString('<nav>toggle</nav>', $html);
        self::assertStringContainsString('<span class="lp-filter-count">3 items</span>', $html);
    }

    public function test_the_search_draws_its_field_and_the_body_joins_the_primary_row(): void
    {
        $html = $this->render('<twig:Ds:FilterBar action="/list" :search="{id: \'s\', name: \'q\', value: \'a&b\', label: \'Find\', placeholder: \'Type\'}"><select name="x"></select></twig:Ds:FilterBar>');

        self::assertStringContainsString('<div class="lp-filter-primary" data-action="focusin->autosearch#revealField">', $html);
        self::assertStringContainsString('<label class="sr-only" for="s">Find</label>', $html);
        self::assertStringContainsString('type="search"', $html);
        self::assertStringContainsString('lp-filter-input', $html);
        self::assertStringContainsString('value="a&amp;b"', $html);
        self::assertStringContainsString('data-autosearch-target="field"', $html);
        self::assertLessThan(strpos($html, '<select name="x">'), strpos($html, 'lp-filter-search'));
    }

    public function test_a_search_without_a_body_draws_no_primary_row(): void
    {
        $html = $this->render('<twig:Ds:FilterBar action="/list" :search="{id: \'s\', name: \'q\', value: \'\', label: \'Find\', placeholder: \'Type\'}" />');

        self::assertStringContainsString('lp-filter-search', $html);
        self::assertStringNotContainsString('lp-filter-primary', $html);
    }

    public function test_the_secondary_block_follows_the_primary_row_inside_the_form(): void
    {
        $html = $this->render('<twig:Ds:FilterBar action="/list" :search="{id: \'s\', name: \'q\', value: \'\', label: \'Find\', placeholder: \'Type\'}"><b>body</b><twig:block name="secondary"><input type="hidden" name="sort" value="a"></twig:block></twig:Ds:FilterBar>');

        self::assertGreaterThan(strpos($html, '<b>body</b>'), strpos($html, 'name="sort"'));
        self::assertLessThan(strpos($html, '</form>'), strpos($html, 'name="sort"'));
    }

    public function test_the_switch_clear_link_and_count_follow_in_order(): void
    {
        $html = $this->render('<twig:Ds:FilterBar action="/list" :switch="{name: \'archived\', label: \'Show archived\', checked: true, attributes: {\'data-toggle\': true}}" clearHref="/list" clearLabel="Clear" count="5 of 7" countFrame="frame-1" :countAttributes="{id: \'c\'}" />');

        self::assertStringContainsString('<label class="lp-filter-switch">', $html);
        $input = new Crawler($html)->filter('label.lp-filter-switch input.lp-switch');
        self::assertCount(1, $input);
        self::assertSame('checkbox', $input->attr('type'));
        self::assertSame('switch', $input->attr('role'));
        self::assertSame('archived', $input->attr('name'));
        self::assertNotNull($input->attr('checked'));
        self::assertNotNull($input->attr('data-toggle'));
        self::assertSame('change->autosearch#search', $input->attr('data-action'));
        self::assertStringContainsString('<a href="/list" data-autosearch-target="clearButton" class="lp-filter-clear">Clear</a>', $html);
        self::assertStringContainsString('<turbo-frame id="frame-1" class="lp-live-frame"><span class="lp-filter-count" id="c">5 of 7</span></turbo-frame>', $html);
        self::assertLessThan(strpos($html, 'lp-filter-clear'), strpos($html, 'lp-filter-switch'));
        self::assertLessThan(strpos($html, 'lp-filter-count'), strpos($html, '</form>'));
    }

    public function test_an_unchecked_switch_is_not_checked(): void
    {
        $html = $this->render('<twig:Ds:FilterBar action="/list" :switch="{name: \'archived\', label: \'Show archived\', checked: false}" />');

        self::assertStringNotContainsString('checked', $html);
    }
}
