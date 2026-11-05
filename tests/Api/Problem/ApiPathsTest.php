<?php

declare(strict_types=1);

namespace App\Tests\Api\Problem;

use App\Api\Problem\ApiPaths;
use PHPUnit\Framework\TestCase;

final class ApiPathsTest extends TestCase
{
    public function test_the_bridge_paths_never_take_the_public_budget(): void
    {
        self::assertFalse(ApiPaths::isPublic('/api/v1/bridge/card-holds'));
        self::assertFalse(ApiPaths::isPublic('/api/v1/bridge/projects/loupe/columns'));
        self::assertFalse(ApiPaths::isPublic('/api/v1/bridge'));
    }
}
