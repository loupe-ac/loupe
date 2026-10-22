<?php

declare(strict_types=1);

namespace App\Tests\Site;

use App\Site\SiteChrome;
use PHPUnit\Framework\TestCase;

/** The Astro build and the PHP stamp name the same strings. */
final class ChromeMarkersTest extends TestCase
{
    public function test_the_php_constants_equal_the_strings_of_the_docs_build(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2).'/website/src/chrome-markers.mjs');

        self::assertSame(
            [
                'NAV_MARKER' => SiteChrome::NAV_MARKER,
                'FOOTER_MARKER' => SiteChrome::FOOTER_MARKER,
                'MENU_MARKER' => SiteChrome::MENU_MARKER,
                'OG_IMAGE_URL' => SiteChrome::OG_IMAGE_PLACEHOLDER,
            ],
            $this->constants($source),
        );
    }

    /** @return array<string, string> */
    private function constants(string $source): array
    {
        preg_match_all("/export const (\\w+) = '([^']*)';/", $source, $matches);

        return array_combine($matches[1], $matches[2]);
    }
}
