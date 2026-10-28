<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Service;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Repository\ForgeRepositoryRepository;
use App\Module\Forge\Service\ForgeConnectionReaders;
use App\Module\GitHub\Entity\GitHubInstallation;
use App\Module\GitHub\Entity\GitHubRepositorySelection;
use App\Module\GitHub\Repository\GitHubInstallationRepository;
use App\Module\GitHub\Service\GitHubConnectionReader;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GitHubConnectionReaderTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private GitHubConnectionReader $reader;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $installations = self::getContainer()->get(GitHubInstallationRepository::class);
        self::assertInstanceOf(GitHubInstallationRepository::class, $installations);
        $forgeRepositories = self::getContainer()->get(ForgeRepositoryRepository::class);
        self::assertInstanceOf(ForgeRepositoryRepository::class, $forgeRepositories);
        $this->reader = new GitHubConnectionReader($installations, $forgeRepositories);
    }

    public function test_it_supports_github_only(): void
    {
        self::assertTrue($this->reader->supports('github'));
        self::assertFalse($this->reader->supports('gitlab'));
    }

    public function test_it_accepts_owner_and_repository_and_refuses_a_nested_path(): void
    {
        self::assertTrue($this->reader->acceptsPath('owner/repo'));
        self::assertTrue($this->reader->acceptsPath('Owner/re.po_1-x'));
        self::assertFalse($this->reader->acceptsPath('group/sub/repo'));
        self::assertFalse($this->reader->acceptsPath('repo'));
        self::assertFalse($this->reader->acceptsPath('owner/'));
        self::assertFalse($this->reader->acceptsPath('owner/..'));
        self::assertFalse($this->reader->acceptsPath('./repo'));
        self::assertFalse($this->reader->acceptsPath('owner/re po'));
    }

    public function test_it_builds_the_github_pull_request_url(): void
    {
        self::assertSame('https://github.com/acme/loupe/pull/1295', $this->reader->pullRequestUrl('acme/loupe', 1295));
    }

    public function test_it_builds_no_url_for_a_path_it_refuses(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->reader->pullRequestUrl('group/sub/repo', 7);
    }

    public function test_a_project_with_neither_installation_nor_repository_is_not_connected(): void
    {
        self::assertFalse($this->reader->isConnected($this->project()));
    }

    public function test_a_project_with_an_installation_is_connected(): void
    {
        $project = $this->project();
        $this->em->persist(new GitHubInstallation($project, random_int(1_000_000, 9_000_000), 'acme', GitHubRepositorySelection::Selected));
        $this->em->flush();

        self::assertTrue($this->reader->isConnected($project));
    }

    public function test_a_removed_installation_does_not_connect_a_project(): void
    {
        $project = $this->project();
        $installation = new GitHubInstallation($project, random_int(1_000_000, 9_000_000), 'acme', GitHubRepositorySelection::Selected);
        $installation->removedAt = new \DateTimeImmutable();
        $this->em->persist($installation);
        $this->em->flush();

        self::assertFalse($this->reader->isConnected($project));
    }

    public function test_a_project_with_a_watched_github_repository_is_connected(): void
    {
        $project = $this->project();
        $this->em->persist(new ForgeRepository($project, 'github', 'ext-'.uniqid(), 'acme/loupe', ForgeRepositorySource::Hook));
        $this->em->flush();

        self::assertTrue($this->reader->isConnected($project));
    }

    public function test_a_watched_repository_of_another_forge_does_not_connect_a_project(): void
    {
        $project = $this->project();
        $this->em->persist(new ForgeRepository($project, 'gitlab', 'ext-'.uniqid(), 'acme/loupe', ForgeRepositorySource::Hook));
        $this->em->flush();

        self::assertFalse($this->reader->isConnected($project));
    }

    public function test_the_readers_count_the_github_reader_connected_for_a_project_that_watches_a_github_repository(): void
    {
        $project = $this->project();
        $this->em->persist(new ForgeRepository($project, 'github', 'ext-'.uniqid(), 'acme/loupe', ForgeRepositorySource::Hook));
        $this->em->flush();
        $readers = new ForgeConnectionReaders([$this->reader]);

        self::assertSame([$this->reader], $readers->connected($project, []));
        self::assertSame([], $readers->connected($this->project(), []));
    }

    private function project(): Project
    {
        $owner = new User(fullName: 'Riley', email: 'riley-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $project = new Project($owner, 'p-'.uniqid());
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }
}
