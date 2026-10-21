<?php

declare(strict_types=1);

namespace App\Site;

use Dom\HTMLDocument;

/** Reads the links of a rendered site bar or footer, and checks where they point. */
final class ChromeLinkAudit
{
    /** @return list<string> every href in the markup */
    public static function hrefs(string $html): array
    {
        $hrefs = [];
        $document = HTMLDocument::createFromString('<body>'.$html.'</body>', \LIBXML_NOERROR);
        foreach ($document->querySelectorAll('a[href]') as $link) {
            $hrefs[] = (string) $link->getAttribute('href');
        }

        return $hrefs;
    }

    /**
     * Describes each href that is a fragment alone, has no host, or names a host
     * outside the configured hosts and SiteChrome::OUTSIDE_HOSTS. A path that
     * starts with a slash passes only when the page and the link share one host.
     *
     * @param list<string> $configuredHosts
     *
     * @return list<string>
     */
    public static function problems(string $html, array $configuredHosts, bool $allowRootRelative = false): array
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
                if (!$allowRootRelative || !str_starts_with($href, '/') || str_starts_with($href, '//')) {
                    $problems[] = sprintf('%s names no host', $href);
                }
                continue;
            }
            if (!\in_array(strtolower($host), $allowed, true)) {
                $problems[] = sprintf('%s names a host outside the allowed sets', $href);
            }
        }

        return $problems;
    }
}
