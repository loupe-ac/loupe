<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use App\Module\DesignSystem\Catalog;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class MenuComponentTest extends KernelTestCase
{
    private function render(string $template): string
    {
        self::bootKernel();

        return static::getContainer()->get(Environment::class)->createTemplate($template)->render();
    }

    public function test_a_menu_wires_the_popover_controller_and_hides_its_panel(): void
    {
        $html = $this->render('<twig:Ds:Menu id="m1"><twig:block name="trigger"><button type="button">Open</button></twig:block><twig:block name="content">Body</twig:block></twig:Ds:Menu>');

        self::assertStringContainsString('<div class="lp-menu" data-controller="popover">', $html);
        self::assertStringContainsString('<button type="button">Open</button>', $html);
        self::assertStringContainsString('<div class="lp-menu__panel" id="m1" data-popover-target="panel" hidden>Body</div>', $html);
    }

    public function test_each_option_adds_a_modifier_and_the_defaults_add_none(): void
    {
        $html = $this->render('<twig:Ds:Menu id="m2" tone="chrome" align="stretch" direction="up" size="md" density="roomy" class="extra">x</twig:Ds:Menu>');

        self::assertStringContainsString('class="lp-menu lp-menu--chrome lp-menu--stretch lp-menu--up lp-menu--md lp-menu--roomy extra"', $html);
        self::assertStringNotContainsString('lp-menu--light', $this->render('<twig:Ds:Menu id="m3" tone="light" align="end" direction="down" density="compact">x</twig:Ds:Menu>'));
    }

    public function test_the_panel_can_be_a_form_with_its_own_attributes(): void
    {
        $html = $this->render('<twig:Ds:Menu id="m4" as="form" :panel="{method: \'post\', action: \'/move\'}">x</twig:Ds:Menu>');

        self::assertStringContainsString('<form method="post" action="/move" class="lp-menu__panel" id="m4"', $html);
        self::assertStringContainsString('</form>', $html);
    }

    public function test_an_open_menu_shows_its_panel(): void
    {
        $html = $this->render('<twig:Ds:Menu id="m5" open>x</twig:Ds:Menu>');

        self::assertStringNotContainsString('hidden', $html);
    }

    public function test_a_live_menu_holds_a_listbox_that_a_text_field_opens(): void
    {
        $html = $this->render('<twig:Ds:Menu id="m6" live><twig:block name="trigger"><input role="combobox" aria-controls="m6"></twig:block><twig:block name="content">Rows</twig:block></twig:Ds:Menu>');

        self::assertStringContainsString('class="lp-menu lp-menu--live"', $html);
        self::assertStringNotContainsString('data-controller="popover"', $html);
        self::assertStringNotContainsString('data-popover-target', $html);
        self::assertStringContainsString('<div role="listbox" class="lp-menu__panel" id="m6" hidden>Rows</div>', $html);
    }

    public function test_a_menu_without_the_live_option_keeps_its_popover_and_no_listbox_role(): void
    {
        $html = $this->render('<twig:Ds:Menu id="m7">x</twig:Ds:Menu>');

        self::assertStringContainsString('data-controller="popover"', $html);
        self::assertStringNotContainsString('role="listbox"', $html);
    }

    public function test_an_item_is_a_link_with_an_href_and_a_plain_button_without(): void
    {
        $link = $this->render('<twig:Ds:MenuItem href="/a" :current="true" data-x="1">Go</twig:Ds:MenuItem>');
        $button = $this->render('<twig:Ds:MenuItem>Do</twig:Ds:MenuItem>');
        $submit = $this->render('<twig:Ds:MenuItem type="submit" name="n" value="v" disabled>Send</twig:Ds:MenuItem>');

        self::assertStringContainsString('<a class="lp-menu__item" href="/a" aria-current="true" data-x="1">Go</a>', $link);
        self::assertSame('<button class="lp-menu__item" type="button">Do</button>', trim($button));
        self::assertStringContainsString('type="submit"', $submit);
        self::assertStringNotContainsString('type="button"', $submit);
        self::assertStringContainsString(' disabled', $submit);
    }

    public function test_the_current_item_can_name_its_own_aria_current_value(): void
    {
        $html = $this->render('<twig:Ds:MenuItem href="/a" current="page">Go</twig:Ds:MenuItem>');
        $none = $this->render('<twig:Ds:MenuItem href="/a" :current="false">Go</twig:Ds:MenuItem>');

        self::assertStringContainsString('aria-current="page"', $html);
        self::assertStringNotContainsString('aria-current', $none);
    }

    public function test_a_heading_and_a_divider_render_their_classes(): void
    {
        self::assertStringContainsString('<p class="lp-menu__heading">Open in</p>', $this->render('<twig:Ds:MenuHeading>Open in</twig:Ds:MenuHeading>'));
        self::assertStringContainsString('<div class="lp-menu__divider" role="separator"></div>', $this->render('<twig:Ds:MenuDivider />'));
    }

    public function test_a_group_draws_its_heading_and_holds_extra_rows_behind_a_show_more_row(): void
    {
        $html = $this->render('<twig:Ds:MenuGroup heading="Cards (4)" moreLabel="Show 1 more" data-kind="card"><twig:block name="content">Shown</twig:block><twig:block name="more">Hidden</twig:block></twig:Ds:MenuGroup>');

        self::assertStringContainsString('<div class="lp-menu__group" data-kind="card">', $html);
        self::assertStringContainsString('<p class="lp-menu__heading">Cards (4)</p>', $html);
        self::assertStringContainsString('Shown', $html);
        self::assertMatchesRegularExpression('#<details class="lp-menu__more">\s*<summary class="lp-menu__more-toggle">Show 1 more</summary>\s*Hidden#', $html);
    }

    public function test_a_group_without_a_heading_or_a_show_more_label_draws_neither(): void
    {
        $html = $this->render('<twig:Ds:MenuGroup><twig:block name="content">Shown</twig:block><twig:block name="more">Hidden</twig:block></twig:Ds:MenuGroup>');

        self::assertStringNotContainsString('lp-menu__heading', $html);
        self::assertStringNotContainsString('<details', $html);
        self::assertStringNotContainsString('Hidden', $html);
    }

    public function test_the_catalog_lists_the_menu_with_its_old_classes_enforced(): void
    {
        $entries = [];
        foreach (new Catalog()->entries() as $entry) {
            $entries[$entry->name] = $entry;
        }

        self::assertArrayHasKey('Menu', $entries);
        self::assertSame('lp-menu', $entries['Menu']->rootClass);
        self::assertTrue($entries['Menu']->enforced);
        self::assertTrue($entries['Menu']->legacyEnforced);
        self::assertContains('lp-backlog-menu', $entries['Menu']->legacyClasses);
        self::assertContains('lp-switcher', $entries['Menu']->legacyClasses);
    }
}
