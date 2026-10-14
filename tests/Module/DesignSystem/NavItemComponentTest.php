<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class NavItemComponentTest extends KernelTestCase
{
    private function render(string $template): string
    {
        self::bootKernel();

        return static::getContainer()->get(Environment::class)->createTemplate($template)->render();
    }

    public function test_a_link_draws_its_icon_and_label_without_the_current_page_mark(): void
    {
        $html = $this->render('<twig:Ds:NavItem url="/board" icon="lucide:inbox" label="Board" />');

        self::assertStringContainsString('href="/board"', $html);
        self::assertStringContainsString('class="lp-sidebar__link"', $html);
        self::assertStringContainsString('<span class="lp-sidebar__link-label">Board</span>', $html);
        self::assertStringNotContainsString('aria-current', $html);
        self::assertStringNotContainsString('lp-sidebar__link--active', $html);
    }

    public function test_the_active_link_gets_the_modifier_and_aria_current(): void
    {
        $html = $this->render('<twig:Ds:NavItem url="/board" icon="lucide:inbox" label="Board" :active="true" />');

        self::assertStringContainsString('lp-sidebar__link lp-sidebar__link--active', $html);
        self::assertStringContainsString('aria-current="page"', $html);
    }

    public function test_a_count_draws_a_pill_and_zero_still_counts(): void
    {
        self::assertStringContainsString('<span class="lp-sidebar__pill">12</span>', $this->render('<twig:Ds:NavItem url="/d" icon="lucide:inbox" label="Docs" :count="12" />'));
        self::assertStringContainsString('<span class="lp-sidebar__pill">0</span>', $this->render('<twig:Ds:NavItem url="/d" icon="lucide:inbox" label="Docs" :count="0" />'));
        self::assertStringNotContainsString('lp-sidebar__pill', $this->render('<twig:Ds:NavItem url="/d" icon="lucide:inbox" label="Docs" />'));
    }

    public function test_extra_attributes_and_content_land_on_the_link(): void
    {
        $html = $this->render('<twig:Ds:NavItem url="/i" icon="lucide:inbox" label="Inbox" data-controller="inbox-pill"><b>extra</b></twig:Ds:NavItem>');

        self::assertStringContainsString('data-controller="inbox-pill"', $html);
        self::assertStringContainsString('<b>extra</b></a>', $html);
    }
}
