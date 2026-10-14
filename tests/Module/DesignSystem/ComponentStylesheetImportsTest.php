<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ComponentStylesheetImportsTest extends TestCase
{
    private const string STYLES = __DIR__.'/../../../assets/styles';

    /**
     * @return iterable<string, array{string}>
     */
    public static function entryStylesheets(): iterable
    {
        yield 'the app' => ['app.css'];
        yield 'the design system export' => ['design-system.css'];
    }

    #[DataProvider('entryStylesheets')]
    public function test_every_component_stylesheet_is_imported(string $entry): void
    {
        $css = (string) file_get_contents(self::STYLES.'/'.$entry);
        $components = glob(self::STYLES.'/components/*.css') ?: [];

        self::assertNotEmpty($components);
        foreach ($components as $path) {
            self::assertStringContainsString("@import './components/".basename($path)."';", $css, basename($path).' is missing from '.$entry);
        }
    }
}
