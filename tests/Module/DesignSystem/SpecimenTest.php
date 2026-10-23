<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use App\Module\DesignSystem\Catalog;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

final class SpecimenTest extends KernelTestCase
{
    /** @return iterable<string, array{string}> */
    public static function entries(): iterable
    {
        foreach (new Catalog()->entries() as $entry) {
            yield $entry->name => [$entry->name];
        }
    }

    #[DataProvider('entries')]
    public function test_every_catalog_entry_has_a_specimen_template(string $name): void
    {
        self::bootKernel();

        self::assertTrue(static::getContainer()->get(Environment::class)->getLoader()->exists('@DesignSystem/specimen/'.strtolower($name).'.html.twig'));
    }

    public function test_the_app_shell_specimen_is_static_and_draws_the_shell_parts(): void
    {
        self::bootKernel();

        $html = static::getContainer()->get(Environment::class)->render('@DesignSystem/specimen/appshell.html.twig', ['variant' => null]);

        foreach (['lp-app', 'lp-sidebar', 'lp-menu lp-menu--chrome', 'lp-avatar', 'lp-shell', 'lp-topbar', 'lp-main', 'lp-sidebar__link--active'] as $class) {
            self::assertStringContainsString($class, $html);
        }
        self::assertStringNotContainsString('data-controller="drawer"', $html);
        self::assertStringNotContainsString('lp-sidebar--drawer', $html);
    }

    public function test_the_app_shell_specimen_counts_each_sidebar_link_the_app_counts(): void
    {
        self::bootKernel();

        $html = static::getContainer()->get(Environment::class)->render('@DesignSystem/specimen/appshell.html.twig', ['variant' => null]);
        $counted = new Crawler($html)->filter('.lp-sidebar__link')->reduce(
            static fn (Crawler $link): bool => $link->filter('.lp-sidebar__pill')->count() > 0,
        )->each(static fn (Crawler $link): string => $link->filter('.lp-sidebar__link-label')->text());

        self::assertSame(['Needs you', 'Agents', 'Activity'], $counted);
    }
}
