<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Service;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Repository\ForgeRepositoryRepository;
use App\Module\Forge\Service\BranchReaders;
use App\Module\GitHub\Entity\GitHubInstallation;
use App\Module\GitHub\Entity\GitHubRepositorySelection;
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

        self::assertTrue($this->reader()->exists($project, 'https://github.com/loupe-ac/loupe.git', 'epic/852'));
        self::assertSame('https://api.github.com/repos/loupe-ac/loupe/branches/epic/852', $this->requests[1]['url']);
    }

    public function test_it_encodes_each_segment_of_the_branch_name(): void
    {
        $project = $this->connected('loupe-ac/loupe', 74_002);
        $this->responses = [$this->token(), new MockResponse('{}', ['http_code' => 200])];

        self::assertTrue($this->reader()->exists($project, 'git@github.com:loupe-ac/loupe.git', 'team x/e#1?'));
        self::assertSame('https://api.github.com/repos/loupe-ac/loupe/branches/team%20x/e%231%3F', $this->requests[1]['url']);
    }

    public function test_a_404_means_the_branch_is_missing(): void
    {
        $project = $this->connected('loupe-ac/loupe', 74_003);
        $this->responses = [$this->token(), new MockResponse('{"message":"Branch not found"}', ['http_code' => 404])];

        self::assertFalse($this->reader()->exists($project, 'https://github.com/loupe-ac/loupe', 'epic/852'));
        self::assertCount(2, $this->requests);
    }

    public function test_a_server_error_reads_false_and_throws_nothing(): void
    {
        $project = $this->connected('loupe-ac/loupe', 74_004);
        $this->responses = [$this->token(), new MockResponse('{"message":"Server Error"}', ['http_code' => 500])];

        self::assertFalse($this->reader()->exists($project, 'https://github.com/loupe-ac/loupe', 'epic/852'));
        self::assertCount(2, $this->requests);
    }

    public function test_a_rate_limit_reads_false(): void
    {
        $project = $this->connected('loupe-ac/loupe', 74_005);
        $this->responses = [$this->token(), new MockResponse('{"message":"rate limit"}', ['http_code' => 403, 'response_headers' => ['x-ratelimit-remaining' => '0']])];

        self::assertFalse($this->reader()->exists($project, 'https://github.com/loupe-ac/loupe', 'epic/852'));
    }

    public function test_a_missing_app_configuration_reads_false_with_no_request(): void
    {
        $project = $this->connected('loupe-ac/loupe', 74_006);

        self::assertFalse($this->reader(appId: null)->exists($project, 'https://github.com/loupe-ac/loupe', 'epic/852'));
        self::assertSame([], $this->requests);
    }

    public function test_a_remote_of_another_forge_reads_false_with_no_request(): void
    {
        $project = $this->connected('loupe-ac/loupe', 74_007);

        self::assertFalse($this->reader()->exists($project, 'https://gitlab.com/loupe-ac/loupe.git', 'epic/852'));
        self::assertSame([], $this->requests);
    }

    public function test_a_repository_the_project_did_not_connect_reads_false_with_no_request(): void
    {
        $project = $this->connected('loupe-ac/loupe', 74_008);

        self::assertFalse($this->reader()->exists($project, 'https://github.com/loupe-ac/other', 'epic/852'));
        self::assertSame([], $this->requests);
    }

    public function test_a_repository_that_only_another_project_connected_reads_false_with_no_request(): void
    {
        $this->connected('loupe-ac/loupe', 74_009);

        self::assertFalse($this->reader()->exists($this->project(), 'https://github.com/loupe-ac/loupe', 'epic/852'));
        self::assertSame([], $this->requests);
    }

    public function test_the_registry_reads_true_when_any_reader_does(): void
    {
        $project = $this->connected('loupe-ac/loupe', 74_010);
        $this->responses = [$this->token(), new MockResponse('{}', ['http_code' => 200])];

        self::assertTrue(new BranchReaders([$this->reader()])->exists($project, 'https://github.com/loupe-ac/loupe', 'epic/852'));
        self::assertFalse(new BranchReaders([])->exists($project, 'https://github.com/loupe-ac/loupe', 'epic/852'));
    }

    private function connected(string $path, int $installationId): Project
    {
        $project = $this->project();
        $this->em->persist(new GitHubInstallation($project, $installationId, 'loupe-ac', GitHubRepositorySelection::Selected));
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
