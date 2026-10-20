<?php

declare(strict_types=1);

namespace App\Tests\Site;

use App\Site\SiteChrome;
use Symfony\Component\DomCrawler\Crawler;

/** Reads the links of a rendered site bar or footer, and checks where they point. */
final class ChromeLinks
{
    /** @return list<string> every href in the markup */
    public static function hrefs(string $html): array
    {
        return (new Crawler('<body>'.$html.'</body>'))
            ->filter('a[href]')
            ->each(static fn (Crawler $link): string => (string) $link->attr('href'));
    }

    /**
     * Describes each href that is a fragment alone, has no host, or names a host
     * outside the configured hosts and SiteChrome::OUTSIDE_HOSTS.
     *
     * @param list<string> $configuredHosts
     *
     * @return list<string>
     */
    public static function problems(string $html, array $configuredHosts): array
    {
        $allowed = array_map(strtolower(...), [...$configuredHosts, ...SiteChrome::OUTSIDE_HOSTS]);
        $problems = [];
        foreach (self::hrefs($html) as $href) {
            if (str_starts_with($href, '#')) {
                $problems[] = sprintf('%s is a fragment of the current page', $href);
                continue;
            }
            $host = parse_url($href, \PHP_URL_HOST);
            if (!\is_string($host)) {
                $problems[] = sprintf('%s names no host', $href);
                continue;
            }
            if (!\in_array(strtolower($host), $allowed, true)) {
                $problems[] = sprintf('%s names a host outside the allowed sets', $href);
            }
        }

        return $problems;
    }
}
