<?php

declare(strict_types=1);

namespace App\Module\Notification\Service;

/** A path on this site that a redirect may follow, or null. */
final readonly class LocalPath
{
    private const int MAX_LENGTH = 2048;

    /**
     * Accepts one slash and then no second slash, no backslash, no space and no control
     * character. A browser reads "/\" and "/<tab>/" as "//", which names another host.
     */
    public static function from(?string $value): ?string
    {
        if (null === $value || \strlen($value) > self::MAX_LENGTH) {
            return null;
        }

        return 1 === preg_match('#\A/(?![/\\\\])[^\\\\\x00-\x20\x7f]*\z#', $value) ? $value : null;
    }

    /**
     * The path of a full URL, when the URL is on the given origin. The fragment drops, because a browser never sends it in a Referer.
     *
     * @param string $origin scheme, host and any port, with no trailing slash
     */
    public static function ofSameOrigin(?string $url, string $origin): ?string
    {
        if (null === $url || !str_starts_with($url, $origin.'/')) {
            return null;
        }

        $rest = substr($url, \strlen($origin));

        return self::from(substr($rest, 0, strcspn($rest, '#')));
    }
}
