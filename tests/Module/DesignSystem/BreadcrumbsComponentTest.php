<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class BreadcrumbsComponentTest extends KernelTestCase
{
    private function render(string $template): string
    {
        self::bootKernel();

        return static::getContainer()->get(Environment::class)->createTemplate($template)->render();
    }

    public function test_the_trail_is_a_labelled_nav_with_an_ordered_list(): void
    {
        $html = $this->render('<twig:Ds:Breadcrumbs :items="[{label: \'Documents\', url: \'/d\'}, {label: \'Tech design\'}]" />');

        self::assertStringContainsString('<nav class="lp-crumbs" aria-label="Breadcrumb">', $html);
        self::assertStringContainsString('<ol class="lp-crumbs__list">', $html);
        self::assertStringContainsString('<a class="lp-crumbs__link" href="/d">Documents</a>', $html);
    }

    public function test_the_last_item_is_the_current_page_and_never_a_link(): void
    {
        $html = $this->render('<twig:Ds:Breadcrumbs :items="[{label: \'Documents\', url: \'/d\'}, {label: \'Tech design\', url: \'/x\'}]" />');

        self::assertStringContainsString('<span class="lp-crumbs__here" aria-current="page">Tech design</span>', $html);
        self::assertSame(1, substr_count($html, 'aria-current="page"'));
        self::assertStringNotContainsString('href="/x"', $html);
    }

    public function test_each_separator_is_hidden_from_a_screen_reader_and_the_last_item_has_none(): void
    {
        $html = $this->render('<twig:Ds:Breadcrumbs :items="[{label: \'A\', url: \'/a\'}, {label: \'B\', url: \'/b\'}, {label: \'C\'}]" />');

        self::assertSame(2, substr_count($html, '<span class="lp-crumbs__sep" aria-hidden="true">/</span>'));
    }

    public function test_an_item_without_a_url_is_plain_text(): void
    {
        $html = $this->render('<twig:Ds:Breadcrumbs :items="[{label: \'Account\'}, {label: \'Settings\'}]" />');

        self::assertStringContainsString('<span class="lp-crumbs__link">Account</span>', $html);
        self::assertStringNotContainsString('<a ', $html);
    }

    public function test_a_single_item_is_only_the_current_page(): void
    {
        $html = $this->render('<twig:Ds:Breadcrumbs :items="[{label: \'Board\'}]" />');

        self::assertStringContainsString('lp-crumbs__item lp-crumbs__item--current', $html);
        self::assertStringNotContainsString('lp-crumbs__sep', $html);
    }

    public function test_the_content_block_draws_after_the_list(): void
    {
        $html = $this->render('<twig:Ds:Breadcrumbs :items="[{label: \'Doc\'}]"><span class="lp-version-pill">v2</span></twig:Ds:Breadcrumbs>');

        self::assertMatchesRegularExpression('~</ol>\s*<span class="lp-version-pill">v2</span>\s*</nav>~', $html);
    }
}
