<?php

declare(strict_types=1);

namespace App\Tests\DevSeed;

use App\DevSeed\SeedContext;
use App\Module\Account\Service\Dev\DevAccountSeeder;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\Dev\DevProjectSeeder;
use Psr\Container\ContainerInterface;

final class DevProjectFixture
{
    /** Runs the account and project seeders, and returns the dev project. */
    public static function create(ContainerInterface $container, SeedContext $context): Project
    {
        $container->get(DevAccountSeeder::class)->seed($context);
        $container->get(DevProjectSeeder::class)->seed($context);

        return $container->get(ProjectRepository::class)->find(DevProjectSeeder::devProjectId())
            ?? throw new \LogicException('The dev project is missing.');
    }
}
