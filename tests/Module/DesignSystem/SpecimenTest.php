<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use App\Module\DesignSystem\Catalog;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
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

        foreach (['lp-app', 'lp-sidebar', 'lp-switcher', 'lp-shell', 'lp-topbar', 'lp-main', 'lp-sidebar__link--active'] as $class) {
            self::assertStringContainsString($class, $html);
        }
        self::assertStringNotContainsString('data-controller', $html);
        self::assertStringNotContainsString('lp-sidebar--drawer', $html);
    }
}
