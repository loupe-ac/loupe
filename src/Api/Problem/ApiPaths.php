<?php

declare(strict_types=1);

namespace App\Api\Problem;

/** Which kind of API path a request is on. The renderer, the budget and the spec areas all ask. */
final readonly class ApiPaths
{
    public const string CURRENT_PREFIX = '/api/v1';

    /** The private prefixes of the bridge and the widget. A public promise never covers them. */
    private const array PRIVATE_PREFIXES = ['/api/v1/bridge', '/api/v1/live-review-widget'];

    /** The versions this server answers. A path of any other version gets `api_version_unsupported`. */
    public const array SERVED_VERSIONS = ['v1'];

    /** True for a path that answers Problem JSON: any /api/v<N> path, and the versions route. */
    public static function usesProblemJson(string $path): bool
    {
        return '/api/versions' === $path || 1 === preg_match('#^/api/v\d+(/|$)#', $path);
    }

    public static function isApi(string $path): bool
    {
        return '/api' === $path || str_starts_with($path, '/api/');
    }

    /** True for a path under the public contract, openapi.json and the versions route included. */
    public static function isPublic(string $path): bool
    {
        if ('/api/versions' === $path) {
            return true;
        }
        if (self::CURRENT_PREFIX !== $path && !str_starts_with($path, self::CURRENT_PREFIX.'/')) {
            return false;
        }

        return array_all(self::PRIVATE_PREFIXES, fn ($prefix) => !($path === $prefix || str_starts_with($path, $prefix.'/')));
    }

    /** The requested version of a /api/v<N> path, such as `v2`, or null on any other path. */
    public static function requestedVersion(string $path): ?string
    {
        return 1 === preg_match('#^/api/(v\d+)(/|$)#', $path, $matches) ? $matches[1] : null;
    }
}
