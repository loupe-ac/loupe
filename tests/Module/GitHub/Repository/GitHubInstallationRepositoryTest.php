<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Repository;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\GitHub\Entity\GitHubInstallation;
use App\Module\GitHub\Entity\GitHubRepositorySelection;
use App\Module\GitHub\Repository\GitHubInstallationRepository;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** A project uses an installation when one of its installation rows names it. */
final class GitHubInstallationRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private GitHubInstallationRepository $installations;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $installations = self::getContainer()->get(GitHubInstallationRepository::class);
        self::assertInstanceOf(GitHubInstallationRepository::class, $installations);
        $this->installations = $installations;
    }

    public function test_two_projects_share_one_installation_and_each_sees_it(): void
    {
        [$first, $second, $alone] = [$this->project(), $this->project(), $this->project()];
        $this->em->persist(new GitHubInstallation(8_200_001, 'acme', GitHubRepositorySelection::All));
        $this->row($first, '1', 'acme/one', '8200001');
        $this->row($second, '2', 'acme/two', '8200001');
        $this->em->flush();

        self::assertSame([8_200_001], $this->ids($this->installations->findByProject($first)));
        self::assertSame([8_200_001], $this->ids($this->installations->findByProject($second)));
        self::assertSame([], $this->installations->findByProject($alone));
    }

    public function test_it_ignores_hook_rows_rows_of_another_forge_and_refs_that_are_not_numbers(): void
    {
        $project = $this->project();
        $this->em->persist(new GitHubInstallation(8_200_002, 'acme', GitHubRepositorySelection::All));
        $this->row($project, '1', 'acme/hook', '8200002', ForgeRepositorySource::Hook);
        $this->row($project, '2', 'acme/lab', '8200002', forge: 'gitlab');
        $this->row($project, '3', 'acme/odd', 'not-a-number');
        $this->row($project, '4', 'acme/none', null);
        $this->em->flush();

        self::assertSame([], $this->installations->findByProject($project));
    }

    public function test_a_row_with_no_access_still_names_its_installation(): void
    {
        $project = $this->project();
        $this->em->persist(new GitHubInstallation(8_200_003, 'acme', GitHubRepositorySelection::All));
        $this->row($project, '1', 'acme/lost', '8200003')->accessLostAt = new \DateTimeImmutable();
        $this->em->flush();

        self::assertSame([8_200_003], $this->ids($this->installations->findByProject($project)));
    }

    public function test_the_owner_sees_the_installations_of_all_their_projects_and_nobody_else_does(): void
    {
        $owner = new User(fullName: 'Riley', email: 'installations-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $mine = new Project($owner, 'mine-'.uniqid());
        $theirs = $this->project();
        $this->em->persist($mine);
        $this->em->persist(new GitHubInstallation(8_200_004, 'acme', GitHubRepositorySelection::All));
        $this->em->persist(new GitHubInstallation(8_200_005, 'other', GitHubRepositorySelection::All));
        $this->row($mine, '1', 'acme/one', '8200004');
        $this->row($theirs, '2', 'other/two', '8200005');
        $this->em->flush();

        self::assertSame([8_200_004], $this->ids($this->installations->findByOwner($owner)));
    }

    public function test_it_lists_the_records_that_have_no_account_id(): void
    {
        $this->em->persist(new GitHubInstallation(8_200_006, 'acme', GitHubRepositorySelection::All));
        $this->em->persist(new GitHubInstallation(8_200_007, 'acme', GitHubRepositorySelection::All, 4_200));
        $this->em->flush();

        $missing = $this->ids($this->installations->findWithoutAccountId());

        self::assertContains(8_200_006, $missing);
        self::assertNotContains(8_200_007, $missing);
    }

    private function row(Project $project, string $externalId, string $path, ?string $sourceRef, ForgeRepositorySource $source = ForgeRepositorySource::Installation, string $forge = 'github'): ForgeRepository
    {
        $repository = new ForgeRepository($project, $forge, $externalId.uniqid(), $path, $source, $sourceRef);
        $this->em->persist($repository);

        return $repository;
    }

    private function project(): Project
    {
        $owner = new User(fullName: 'Riley', email: 'installations-'.uniqid().'@example.com', password: 'hashed');
        $project = new Project($owner, 'p-'.uniqid());
        $this->em->persist($owner);
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }

    /**
     * @param list<GitHubInstallation> $installations
     *
     * @return list<int>
     */
    private function ids(array $installations): array
    {
        return array_map(static fn (GitHubInstallation $installation): int => $installation->installationId, $installations);
    }
}
