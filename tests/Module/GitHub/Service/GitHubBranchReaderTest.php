<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Service;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Repository\ForgeRepositoryRepository;
use App\Module\Forge\Service\BranchPresence;
use App\Module\Forge\Service\BranchReaders;
use App\Module\GitHub\Entity\GitHubInstallation;
use App\Module\GitHub\Entity\GitHubRepositorySelection;
use App\Module\GitHub\Repository\GitHubInstallationRepository;
use App\Module\GitHub\Service\GitHubAppApi;
use App\Module\GitHub\Service\GitHubAppConfiguration;
use App\Module\GitHub\Service\GitHubBranchReader;
use App\Module\GitHub\Service\GitHubRepositoryInstallations;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GitHubBranchReaderTest extends KernelTestCase
{
    private const int NOW = 1_790_000_000;

    private EntityManagerInterface $em;

    /** @var list<array{method: string, url: string}> */
    private array $requests = [];

    /** @var list<MockResponse> */
    private array $responses = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    public function test_a_branch_the_forge_returns_exists(): void
    {
        $project = $this->connected('loupe-ac/loupe', 74_001);
        $this->responses = [$this->token(), new MockResponse('{"name":"epic/852"}', ['http_code' => 200])];

        self::assertSame(BranchPresence::Exists, $this->probe($project, 'https://github.com/loupe-ac/loupe.git', 'epic/852'));
        self::assertSame('https://api.github.com/repos/loupe-ac/loupe/branches/epic/852', $this->requests[1]['url']);
    }

    public function test_it_encodes_each_segment_of_the_branch_name(): void
    {
        $project = $this->connected('loupe-ac/loupe', 74_002);
        $this->responses = [$this->token(), new MockResponse('{}', ['http_code' => 200])];

        self::assertSame(BranchPresence::Exists, $this->probe($project, 'git@github.com:loupe-ac/loupe.git', 'team x/e#1?'));
        self::assertSame('https://api.github.com/repos/loupe-ac/loupe/branches/team%20x/e%231%3F', $this->requests[1]['url']);
    }

    public function test_a_404_means_the_branch_is_missing(): void
    {
        $project = $this->connected('loupe-ac/loupe', 74_003);
        $this->responses = [$this->token(), new MockResponse('{"message":"Branch not found"}', ['http_code' => 404])];

        self::assertSame(BranchPresence::Absent, $this->probe($project, 'https://github.com/loupe-ac/loupe', 'epic/852'));
        self::assertCount(2, $this->requests);
    }

    public function test_a_server_error_is_unreadable_and_throws_nothing(): void
    {
        $project = $this->connected('loupe-ac/loupe', 74_004);
        $this->responses = [$this->token(), new MockResponse('{"message":"Server Error"}', ['http_code' => 500])];

        self::assertSame(BranchPresence::Unreadable, $this->probe($project, 'https://github.com/loupe-ac/loupe', 'epic/852'));
        self::assertCount(2, $this->requests);
    }

    public function test_a_rate_limit_is_unreadable(): void
    {
        $project = $this->connected('loupe-ac/loupe', 74_005);
        $this->responses = [$this->token(), new MockResponse('{"message":"rate limit"}', ['http_code' => 403, 'response_headers' => ['x-ratelimit-remaining' => '0']])];

        self::assertSame(BranchPresence::Unreadable, $this->probe($project, 'https://github.com/loupe-ac/loupe', 'epic/852'));
    }

    public function test_a_missing_app_configuration_is_unreadable_with_no_request(): void
    {
        $project = $this->connected('loupe-ac/loupe', 74_006);

        self::assertSame(BranchPresence::Unreadable, $this->probe($project, 'https://github.com/loupe-ac/loupe', 'epic/852', appId: null));
        self::assertSame([], $this->requests);
    }

    public function test_a_remote_of_another_forge_is_not_readable(): void
    {
        $project = $this->connected('loupe-ac/loupe', 74_007);

        self::assertNull($this->reader()->readableRepository($project, 'https://gitlab.com/loupe-ac/loupe.git'));
        self::assertSame([], $this->requests);
    }

    public function test_a_repository_the_project_did_not_connect_is_not_readable(): void
    {
        $project = $this->connected('loupe-ac/loupe', 74_008);

        self::assertNull($this->reader()->readableRepository($project, 'https://github.com/loupe-ac/other'));
        self::assertSame([], $this->requests);
    }

    public function test_a_repository_that_only_another_project_connected_is_not_readable(): void
    {
        $this->connected('loupe-ac/loupe', 74_009);

        self::assertNull($this->reader()->readableRepository($this->project(), 'https://github.com/loupe-ac/loupe'));
        self::assertSame([], $this->requests);
    }

    public function test_the_registry_answers_the_first_reader_that_can(): void
    {
        $project = $this->connected('loupe-ac/loupe', 74_010);
        $this->responses = [$this->token(), new MockResponse('{}', ['http_code' => 200])];
        $registry = new BranchReaders([$this->reader()]);

        $row = $registry->readableRepository($project, 'https://github.com/loupe-ac/loupe');
        self::assertInstanceOf(ForgeRepository::class, $row);
        self::assertSame(BranchPresence::Exists, $registry->presence($project, $row, 'epic/852'));
        self::assertNull(new BranchReaders([])->readableRepository($project, 'https://github.com/loupe-ac/loupe'));
        self::assertSame(BranchPresence::Unreadable, new BranchReaders([])->presence($project, $row, 'epic/852'));
    }

    public function test_a_row_with_lost_access_a_removed_installation_and_a_suspended_one_are_not_readable(): void
    {
        $project = $this->connected('loupe-ac/loupe', 74_011);
        $row = $this->reader()->readableRepositoryAt($project, 'github', 'loupe-ac/loupe');
        self::assertInstanceOf(ForgeRepository::class, $row);

        $installation = self::getContainer()->get(GitHubInstallationRepository::class)->findOneByInstallationId(74_011);
        self::assertInstanceOf(GitHubInstallation::class, $installation);
        $installation->suspendedAt = new \DateTimeImmutable();
        $this->em->flush();
        self::assertNull($this->reader()->readableRepositoryAt($project, 'github', 'loupe-ac/loupe'));

        $installation->suspendedAt = null;
        $installation->removedAt = new \DateTimeImmutable();
        $this->em->flush();
        self::assertNull($this->reader()->readableRepositoryAt($project, 'github', 'loupe-ac/loupe'));

        $installation->removedAt = null;
        $row->accessLostAt = new \DateTimeImmutable();
        $this->em->flush();
        self::assertNull($this->reader()->readableRepositoryAt($project, 'github', 'loupe-ac/loupe'));
        self::assertSame([], $this->requests);
    }

    public function test_a_repository_that_only_a_plain_webhook_delivers_is_not_readable(): void
    {
        $project = $this->project();
        $this->em->persist(new ForgeRepository($project, 'github', '74012', 'loupe-ac/loupe', ForgeRepositorySource::Hook));
        $this->em->flush();

        self::assertNull($this->reader()->readableRepositoryAt($project, 'github', 'loupe-ac/loupe'));
        self::assertNull($this->reader()->readableRepository($project, 'https://github.com/loupe-ac/loupe'));
    }

    public function test_a_path_is_readable_only_on_its_own_forge(): void
    {
        $project = $this->connected('loupe-ac/loupe', 74_013);

        self::assertNull($this->reader()->readableRepositoryAt($project, 'gitlab', 'loupe-ac/loupe'));
        self::assertInstanceOf(ForgeRepository::class, $this->reader()->readableRepositoryAt($project, 'github', 'LOUPE-AC/loupe'));
    }

    private function probe(Project $project, string $remote, string $branch, ?string $appId = '123456'): ?BranchPresence
    {
        $reader = $this->reader($appId);
        $row = $reader->readableRepository($project, $remote);

        return null === $row ? null : $reader->presence($project, $row, $branch);
    }

    private function connected(string $path, int $installationId): Project
    {
        $project = $this->project();
        $this->em->persist(new GitHubInstallation($installationId, 'loupe-ac', GitHubRepositorySelection::Selected));
        $this->em->persist(new ForgeRepository($project, 'github', (string) $installationId, $path, ForgeRepositorySource::Installation, (string) $installationId));
        $this->em->flush();

        return $project;
    }

    private function project(): Project
    {
        $user = new User(fullName: 'Riley', email: 'branch-reader-'.uniqid().'@example.com', password: 'hashed');
        $project = new Project($user, 'branch-reader-'.uniqid());
        $this->em->persist($user);
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }

    private function reader(?string $appId = '123456'): GitHubBranchReader
    {
        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url];

            return array_shift($this->responses) ?? throw new \LogicException('Unexpected request '.$method.' '.$url);
        }, 'https://api.github.com');
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $pem));
        self::assertIsString($pem);

        $repositories = self::getContainer()->get(ForgeRepositoryRepository::class);
        self::assertInstanceOf(ForgeRepositoryRepository::class, $repositories);
        $installations = self::getContainer()->get(GitHubRepositoryInstallations::class);
        self::assertInstanceOf(GitHubRepositoryInstallations::class, $installations);

        return new GitHubBranchReader(
            $repositories,
            $installations,
            new GitHubAppApi($client, new GitHubAppConfiguration(null, null, null, null, $appId, $pem), new MockClock('@'.self::NOW)),
            new NullLogger(),
        );
    }

    private function token(): MockResponse
    {
        return new MockResponse('{"token":"ghs_token"}', ['http_code' => 201]);
    }
}
