<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use App\Module\DesignSystem\Command\ExportDesignSystemCommand;
use App\Module\DesignSystem\Command\ExportDesignSystemHandler;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Twig\Environment;

final class SettingsLayoutParityTest extends KernelTestCase
{
    private const string ITEMS = "[{label: 'General', url: '/a', icon: 'lucide:settings', active: true}, {label: 'Columns', url: '/b', icon: 'lucide:bot', active: false}]";

    private function render(string $template): string
    {
        self::bootKernel();

        return static::getContainer()->get(Environment::class)->createTemplate($template)->render();
    }

    /** @return list<string> */
    private function classNames(string $source): array
    {
        preg_match_all('/lp-[a-z_-]+/', $source, $matches);

        return array_values(array_unique($matches[0]));
    }

    public function test_the_layout_draws_the_header_then_the_navigation_then_the_content(): void
    {
        $html = $this->render('<twig:Ds:SettingsLayout title="Settings" description="All of them" navLabel="Sections" :items="'.self::ITEMS.'" class="extra"><p id="body">Body</p></twig:Ds:SettingsLayout>');

        self::assertStringContainsString('<div class="lp-settings extra">', $html);
        self::assertStringContainsString('<h1 class="lp-workspace-title">Settings</h1>', $html);
        self::assertStringContainsString('<nav class="lp-settings-nav" aria-label="Sections">', $html);
        self::assertStringContainsString('<div class="lp-settings-content">', $html);
        self::assertLessThan(strpos($html, 'lp-settings-nav'), strpos($html, 'lp-page-header'));
        self::assertLessThan(strpos($html, 'id="body"'), strpos($html, 'lp-settings-nav'));
    }

    public function test_only_the_open_item_is_marked_active_and_current(): void
    {
        $html = $this->render('<twig:Ds:SettingsLayout title="Settings" :items="'.self::ITEMS.'" />');

        self::assertSame(1, substr_count($html, 'lp-settings-nav__item--active'));
        self::assertSame(1, substr_count($html, 'aria-current="page"'));
        self::assertMatchesRegularExpression('#<a class="lp-settings-nav__item lp-settings-nav__item--active" href="/a" aria-current="page">\s*<svg#', $html);
        self::assertMatchesRegularExpression('#<a class="lp-settings-nav__item" href="/b">#', $html);
    }

    public function test_the_columns_option_adds_its_modifiers(): void
    {
        $plain = $this->render('<twig:Ds:SettingsLayout title="Settings" />');
        $columns = $this->render('<twig:Ds:SettingsLayout title="Settings" :columns="true" />');

        self::assertStringNotContainsString('--columns', $plain);
        self::assertStringContainsString('class="lp-settings lp-settings--columns"', $columns);
        self::assertStringContainsString('class="lp-settings-layout lp-settings-layout--columns"', $columns);
    }

    public function test_the_exported_wrapper_uses_the_same_class_names_as_the_twig_component(): void
    {
        $html = $this->render(
            '<twig:Ds:SettingsLayout title="A" description="B" :items="'.self::ITEMS.'" :columns="true">x</twig:Ds:SettingsLayout>',
        );

        $directory = sys_get_temp_dir().'/ds-export-parity-'.bin2hex(random_bytes(4));
        $handler = static::getContainer()->get(ExportDesignSystemHandler::class);
        self::assertInstanceOf(ExportDesignSystemHandler::class, $handler);

        try {
            $handler(new ExportDesignSystemCommand($directory));
            $jsx = (string) file_get_contents($directory.'/components/layout/SettingsLayout.jsx');
        } finally {
            new Filesystem()->remove($directory);
        }

        $expected = $this->classNames($jsx);
        $actual = $this->classNames($html);
        sort($expected);
        sort($actual);

        self::assertSame($actual, $expected);
    }
}
