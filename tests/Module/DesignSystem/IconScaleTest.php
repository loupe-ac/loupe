<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use App\Module\DesignSystem\Catalog;
use PHPUnit\Framework\TestCase;

final class IconScaleTest extends TestCase
{
    private const string PROJECT_DIR = __DIR__.'/../../..';

    public function test_the_catalog_lists_the_icon_as_a_class_with_six_sizes(): void
    {
        $entry = array_find(new Catalog()->entries(), static fn ($entry): bool => 'Icon' === $entry->name);

        self::assertNotNull($entry);
        self::assertSame('lp-icon', $entry->rootClass);
        self::assertTrue($entry->classOnly);
        self::assertSame(['xs', 'sm', 'md', 'lg', 'xl', '2xl'], $entry->variants);
    }

    public function test_the_stylesheet_defines_each_size_of_the_scale(): void
    {
        $css = (string) file_get_contents(self::PROJECT_DIR.'/assets/styles/components/icon.css');

        foreach (['xs' => 'size-3', 'sm' => 'size-3.5', 'md' => 'size-4', 'lg' => 'size-5', 'xl' => 'size-8', '2xl' => 'size-12'] as $name => $utility) {
            self::assertMatchesRegularExpression('/\.lp-icon--'.$name.' \{\n\s*@apply '.preg_quote($utility, '/').';/', $css);
        }
    }

    public function test_no_template_sizes_an_icon_with_a_raw_utility(): void
    {
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::PROJECT_DIR.'/templates', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!str_ends_with((string) $file, '.twig') || str_contains((string) $file, '/Landing/')) {
                continue;
            }
            if (1 === preg_match('/<twig:(?:ux:icon|UX:Icon)\b[^>]*class="[^"]*\b(?:w|h|size)-\d|\biconClass="[^"]*\b(?:w|h|size)-\d|icon\.html\.twig\'[^\n]*\bclass: \'[^\']*\b(?:w|h|size)-\d/', (string) file_get_contents((string) $file))) {
                $offenders[] = substr((string) $file, strlen(self::PROJECT_DIR) + 1);
            }
        }

        self::assertSame([], $offenders);
    }
}
