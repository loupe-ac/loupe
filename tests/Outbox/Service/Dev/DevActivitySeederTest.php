<?php

declare(strict_types=1);

namespace App\Tests\Outbox\Service\Dev;

use App\Module\Project\Repository\ProjectRepository;
use App\Outbox\Repository\OutboxEventRepository;
use App\Outbox\Service\Dev\DevActivitySeeder;
use App\Tests\DevSeed\DevProjectFixture;
use App\Tests\DevSeed\SeedContextFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DevActivitySeederTest extends KernelTestCase
{
    public function test_it_fills_three_activity_pages_and_leaves_the_twin_empty(): void
    {
        self::bootKernel();
        $context = SeedContextFactory::create(self::getContainer());
        $project = DevProjectFixture::create(self::getContainer(), $context);

        self::getContainer()->get(DevActivitySeeder::class)->seed($context);

        $events = self::getContainer()->get(OutboxEventRepository::class);
        self::assertCount(DevActivitySeeder::EVENT_COUNT, $events->findBy(['project' => $project]));
        self::assertGreaterThan(40, DevActivitySeeder::EVENT_COUNT);
        self::assertNotEmpty($events->findUnsentForProject($project));
        $twin = self::getContainer()->get(ProjectRepository::class)->find(DevActivitySeeder::emptyProjectId());
        self::assertNotNull($twin);
        self::assertSame([], $events->findBy(['project' => $twin]));
    }

    public function test_a_second_run_writes_no_row(): void
    {
        self::bootKernel();
        $context = SeedContextFactory::create(self::getContainer());
        $project = DevProjectFixture::create(self::getContainer(), $context);
        $seeder = self::getContainer()->get(DevActivitySeeder::class);

        $seeder->seed($context);
        $seeder->seed($context);

        self::assertCount(DevActivitySeeder::EVENT_COUNT, self::getContainer()->get(OutboxEventRepository::class)->findBy(['project' => $project]));
    }
}
