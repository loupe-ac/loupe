<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Service\Dev;

use App\DevSeed\DevSeedId;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\Dev\DevProjectSeeder;
use App\Tests\DevSeed\SeedContextFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DevProjectSeederTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_it_creates_the_dev_project_with_the_fixed_id_as_the_newest(): void
    {
        $owner = $this->owner();
        $other = new Project($owner, 'Other', createdAt: new \DateTimeImmutable('2026-11-01 11:59:59'));
        $this->em()->persist($other);
        $this->em()->flush();

        $this->runSeeder($owner);

        $project = $this->projects()->find(DevSeedId::of('project/project/dev-project'));
        self::assertInstanceOf(Project::class, $project);
        self::assertSame('Dev Project', $project->name);
        self::assertSame('dev-project', $project->slug);
        self::assertSame($project, $this->projects()->findNewestByOwner($owner, 5)[0]);
    }

    public function test_a_second_run_writes_nothing(): void
    {
        $owner = $this->owner();
        $this->runSeeder($owner);
        $this->runSeeder($owner);

        self::assertCount(1, $this->projects()->findBy(['owner' => $owner]));
    }

    public function test_it_renames_an_old_dev_project_that_has_another_id(): void
    {
        $owner = $this->owner();
        $old = new Project($owner, 'Dev Project');
        $this->em()->persist($old);
        $this->em()->flush();
        $oldId = $old->id;
        self::assertNotNull($oldId);

        $this->runSeeder($owner);
        $this->em()->clear();

        $renamed = $this->projects()->find($oldId);
        self::assertSame(\sprintf('Dev Project (old %s)', substr((string) $oldId, 0, 8)), $renamed?->name);
        self::assertNotSame('dev-project', $renamed->slug);
        self::assertSame('Dev Project', $this->projects()->find(DevSeedId::of('project/project/dev-project'))?->name);
    }

    private function runSeeder(User $owner): void
    {
        $context = SeedContextFactory::create(self::getContainer());
        // The context finds the owner by email, so the test owner takes the dev email.
        self::assertSame($owner->email, $context->devUser()->email);
        self::getContainer()->get(DevProjectSeeder::class)->seed($context);
        $this->em()->clear();
    }

    private function owner(): User
    {
        $users = self::getContainer()->get(\App\Module\Account\Repository\UserRepository::class);
        $existing = $users->findOneByEmail('dev@loupe.test');
        if ($existing instanceof User) {
            foreach ($this->projects()->findBy(['owner' => $existing]) as $project) {
                $this->em()->remove($project);
            }
            $this->em()->flush();

            return $existing;
        }
        $owner = new User('Dev User', 'dev@loupe.test');
        $this->em()->persist($owner);
        $this->em()->flush();

        return $owner;
    }

    private function projects(): ProjectRepository
    {
        return self::getContainer()->get(ProjectRepository::class);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
