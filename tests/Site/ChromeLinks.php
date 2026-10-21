<?php

declare(strict_types=1);

namespace App\Tests\Site;

use App\Site\ChromeLinkAudit;

/** Test-side name for the production link audit, which the docs stamp runs too. */
final class ChromeLinks
{
    /** @return list<string> every href in the markup */
    public static function hrefs(string $html): array
    {
        return ChromeLinkAudit::hrefs($html);
    }

    /**
     * @param list<string> $configuredHosts
     *
     * @return list<string>
     */
    public static function problems(string $html, array $configuredHosts): array
    {
        return ChromeLinkAudit::problems($html, $configuredHosts);
    }
}
