<?php

declare(strict_types=1);

namespace App\Tests\Module\Forge\Service;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\ProjectRepositories;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ForgeProjectRepositoriesTest extends KernelTestCase
{
    public function test_it_lists_the_repositories_of_one_project_oldest_first(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $owner = new User(fullName: 'Riley', email: 'riley-'.uniqid().'@example.com', password: 'hashed');
        $em->persist($owner);
        $project = new Project($owner, 'refs-'.uniqid());
        $other = new Project($owner, 'refs-other-'.uniqid());
        $em->persist($project);
        $em->persist($other);
        $first = new ForgeRepository($project, 'github', 'ext-1', 'acme/first', ForgeRepositorySource::Hook, createdAt: new \DateTimeImmutable('-2 days'));
        $second = new ForgeRepository($project, 'github', 'ext-2', 'acme/second', ForgeRepositorySource::Hook, createdAt: new \DateTimeImmutable('-1 day'));
        $em->persist($first);
        $em->persist($second);
        $em->persist(new ForgeRepository($other, 'github', 'ext-3', 'acme/third', ForgeRepositorySource::Hook));
        $em->flush();
        $em->clear();

        $refs = $this->repositories()->of($project->requireId());

        self::assertSame(['acme/first', 'acme/second'], array_map(static fn ($ref): string => $ref->path, $refs));
        self::assertTrue($first->id?->equals($refs[0]->id));
        self::assertSame('ext-1', $refs[0]->externalId);
        self::assertSame('github', $refs[0]->forge);
    }

    private function repositories(): ProjectRepositories
    {
        $repositories = self::getContainer()->get(ProjectRepositories::class);
        self::assertInstanceOf(ProjectRepositories::class, $repositories);

        return $repositories;
    }
}
