<?php

declare(strict_types=1);

namespace App\Tests\DevSeed;

use App\DevSeed\DevSeedIds;
use App\DevSeed\SeedContext;
use App\Module\Account\Repository\UserRepository;
use Psr\Container\ContainerInterface;

final class SeedContextFactory
{
    public static function create(ContainerInterface $container, bool $keepFlagDefaults = false): SeedContext
    {
        return new SeedContext(
            new \DateTimeImmutable('2026-11-01 12:00:00'),
            $container->get(DevSeedIds::class),
            $container->get(UserRepository::class),
            $keepFlagDefaults,
        );
    }
}
