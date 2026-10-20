<?php

declare(strict_types=1);

namespace App\Tests;

use App\Kernel;
use PHPUnit\Framework\TestCase;

final class KernelTest extends TestCase
{
    public function test_the_test_cache_lives_in_the_temp_dir_keyed_by_the_project_dir(): void
    {
        $kernel = new Kernel('test', false);
        $expected = sys_get_temp_dir().'/loupe-test-cache/'.hash('xxh3', $kernel->getProjectDir()).'/test';

        self::assertSame($expected, $kernel->getCacheDir());
        self::assertSame($expected, $kernel->getBuildDir());
    }

    public function test_the_dev_cache_stays_in_the_project_dir(): void
    {
        $kernel = new Kernel('dev', false);

        self::assertSame($kernel->getProjectDir().'/var/cache/dev', $kernel->getCacheDir());
        self::assertSame($kernel->getProjectDir().'/var/cache/dev', $kernel->getBuildDir());
    }
}
