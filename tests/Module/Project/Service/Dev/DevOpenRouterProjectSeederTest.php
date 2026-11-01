<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Service\Dev;

use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\Dev\DevOpenRouterProjectSeeder;
use App\Tests\DevSeed\DevProjectFixture;
use App\Tests\DevSeed\SeedContextFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DevOpenRouterProjectSeederTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_it_creates_the_project_with_the_fixed_id_once(): void
    {
        $context = SeedContextFactory::create(self::getContainer());
        DevProjectFixture::create(self::getContainer(), $context);
        $seeder = self::getContainer()->get(DevOpenRouterProjectSeeder::class);

        $seeder->seed($context);
        $seeder->seed($context);

        $projects = self::getContainer()->get(ProjectRepository::class);
        $project = $projects->find(DevOpenRouterProjectSeeder::projectId());
        self::assertSame('OpenRouter Project', $project?->name);
        self::assertCount(1, $projects->findBy(['name' => 'OpenRouter Project']));
    }

    public function test_it_renames_an_old_project_that_has_another_id(): void
    {
        $context = SeedContextFactory::create(self::getContainer());
        DevProjectFixture::create(self::getContainer(), $context);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $old = new Project($context->devUser(), 'OpenRouter Project');
        $em->persist($old);
        $em->flush();
        $oldId = $old->id;
        self::assertNotNull($oldId);

        self::getContainer()->get(DevOpenRouterProjectSeeder::class)->seed($context);
        $em->clear();

        $projects = self::getContainer()->get(ProjectRepository::class);
        self::assertSame(\sprintf('OpenRouter Project (old %s)', substr((string) $oldId, 0, 8)), $projects->find($oldId)?->name);
        self::assertSame('OpenRouter Project', $projects->find(DevOpenRouterProjectSeeder::projectId())?->name);
    }
}
