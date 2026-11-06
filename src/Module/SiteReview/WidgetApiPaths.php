<?php

declare(strict_types=1);

namespace App\Module\SiteReview;

/**
 * The API paths the embedded widget calls.
 *
 * CORS and the write rate limit are both path-scoped. A listener that tests one
 * prefix silently exempts every other endpoint the widget reaches, so they share
 * this list.
 *
 * Paths rather than namespaces, so this stays free of the arkitect rule fencing
 * Board as a leaf. A string is not a dependency.
 */
final class WidgetApiPaths
{
    /** @var list<string> */
    public const array PREFIXES = ['/api/v1/live-review-widget'];

    public static function matches(string $path): bool
    {
        return array_any(self::PREFIXES, fn ($prefix) => str_starts_with($path, (string) $prefix));
    }
}
