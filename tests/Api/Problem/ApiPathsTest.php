<?php

declare(strict_types=1);

namespace App\Tests\Api\Problem;

use App\Api\Problem\ApiPaths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApiPathsTest extends TestCase
{
    /** @return iterable<string, array{mixed, ?string}> */
    public static function routes(): iterable
    {
        yield 'a legacy name' => ['api_legacy_bridge_heartbeat', 'api_bridge_heartbeat'];
        yield 'a current name' => ['api_bridge_heartbeat', 'api_bridge_heartbeat'];
        yield 'a name that only contains the prefix' => ['app_api_legacy_x', 'app_api_legacy_x'];
        yield 'no route' => [null, null];
        yield 'not a string' => [42, null];
    }

    #[DataProvider('routes')]
    public function test_a_legacy_route_name_maps_to_its_current_name(mixed $route, ?string $expected): void
    {
        self::assertSame($expected, ApiPaths::canonicalRoute($route));
    }

    public function test_the_bridge_paths_never_take_the_public_budget(): void
    {
        self::assertFalse(ApiPaths::isPublic('/api/v1/bridge/card-holds'));
        self::assertFalse(ApiPaths::isPublic('/api/v1/bridge/projects/loupe/columns'));
        self::assertFalse(ApiPaths::isPublic('/api/v1/bridge'));
    }
}
