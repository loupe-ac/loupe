<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Service;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Service\EpicBranchMergeFailed;
use App\Module\Forge\Service\EpicBranchMergers;
use App\Module\GitHub\Entity\GitHubInstallation;
use App\Module\GitHub\Entity\GitHubRepositorySelection;
use App\Module\GitHub\Service\GitHubAppApi;
use App\Module\GitHub\Service\GitHubAppConfiguration;
use App\Module\GitHub\Service\GitHubEpicBranchMerger;
use App\Module\GitHub\Service\GitHubPullRequestInstallations;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GitHubEpicBranchMergerTest extends KernelTestCase
{
    private const int NOW = 1_790_000_000;

    private EntityManagerInterface $em;

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
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

    public function test_the_registry_hands_github_to_this_merger(): void
    {
        $merger = $this->merger();
        $mergers = new EpicBranchMergers([$merger]);

        self::assertSame($merger, $mergers->for('github'));
        self::assertNull($mergers->for('gitlab'));
    }

    public function test_it_merges_the_default_branch_into_the_epic_branch(): void
    {
        $reference = $this->tracked('Loupe-AC/Loupe.site', 73_001);
        $this->responses = [$this->token(), new MockResponse('{"sha":"abc"}', ['http_code' => 201])];

        $this->merger()->merge($reference, 'epic/820', 'main');

        self::assertCount(2, $this->requests);
        self::assertSame('POST', $this->requests[1]['method']);
        self::assertSame('https://api.github.com/repos/Loupe-AC/Loupe.site/merges', $this->requests[1]['url']);
        $body = $this->requests[1]['options']['body'] ?? null;
        self::assertIsString($body);
        self::assertSame(['base' => 'epic/820', 'head' => 'main'], json_decode($body, true, flags: \JSON_THROW_ON_ERROR));
    }

    public function test_a_204_means_the_epic_branch_already_holds_the_base(): void
    {
        $reference = $this->tracked('loupe-ac/loupe', 73_002);
        $this->responses = [$this->token(), new MockResponse('', ['http_code' => 204])];

        $this->merger()->merge($reference, 'epic/820', 'main');

        self::assertCount(2, $this->requests);
    }

    public function test_a_404_means_the_repository_has_no_epic_branch(): void
    {
        $reference = $this->tracked('loupe-ac/loupe', 73_003);
        $this->responses = [$this->token(), new MockResponse('{"message":"Base does not exist"}', ['http_code' => 404])];

        $this->merger()->merge($reference, 'epic/820', 'main');

        self::assertCount(2, $this->requests);
    }

    public function test_a_409_is_a_permanent_merge_conflict(): void
    {
        $reference = $this->tracked('loupe-ac/loupe', 73_004);
        $this->responses = [$this->token(), new MockResponse('{"message":"Merge conflict"}', ['http_code' => 409])];

        $failure = $this->failure($reference);

        self::assertSame('merge-conflict', $failure->cause);
        self::assertTrue($failure->permanent);
    }

    /** @return iterable<string, array{int}> */
    public static function refusals(): iterable
    {
        yield '401' => [401];
        yield '403' => [403];
    }

    #[DataProvider('refusals')]
    public function test_a_refused_merge_is_a_permanent_permission_failure(int $status): void
    {
        $reference = $this->tracked('loupe-ac/loupe', 73_010 + $status);
        $this->responses = [$this->token(), new MockResponse('{"message":"Resource not accessible by integration"}', ['http_code' => $status])];

        $failure = $this->failure($reference);

        self::assertSame('permission', $failure->cause);
        self::assertTrue($failure->permanent);
    }

    /** @return iterable<string, array{int, array<string, string>, ?int}> */
    public static function rateLimits(): iterable
    {
        yield 'a 403 with no requests left' => [403, ['x-ratelimit-remaining' => '0'], null];
        yield 'a 403 that asks to retry later' => [403, ['retry-after' => '60'], 60];
        yield 'a 429 with the reset time of the limit' => [429, ['x-ratelimit-reset' => (string) (self::NOW + 120)], 120];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('rateLimits')]
    public function test_a_rate_limit_is_a_transient_failure_that_carries_the_delay(int $status, array $headers, ?int $retryAfterSeconds): void
    {
        $reference = $this->tracked('loupe-ac/loupe', 73_020 + $status);
        $this->responses = [$this->token(), new MockResponse('{"message":"API rate limit exceeded"}', ['http_code' => $status, 'response_headers' => $headers])];

        $failure = $this->failure($reference);

        self::assertSame('api_failed_rate_limited', $failure->cause);
        self::assertFalse($failure->permanent);
        self::assertSame($retryAfterSeconds, $failure->retryAfterSeconds);
    }

    public function test_a_server_error_is_a_transient_failure_that_names_the_status(): void
    {
        $reference = $this->tracked('loupe-ac/loupe', 73_005);
        $this->responses = [$this->token(), new MockResponse('{"message":"Server Error"}', ['http_code' => 502])];

        $failure = $this->failure($reference);

        self::assertSame('api_failed_http_status_502', $failure->cause);
        self::assertFalse($failure->permanent);
    }

    public function test_a_missing_app_configuration_is_a_permanent_failure(): void
    {
        $reference = $this->tracked('loupe-ac/loupe', 73_006);

        $failure = $this->failure($reference, appId: null);

        self::assertSame('api_failed_not_configured', $failure->cause);
        self::assertTrue($failure->permanent);
        self::assertSame([], $this->requests);
    }

    public function test_a_repository_without_an_installation_is_a_permanent_failure(): void
    {
        $project = $this->project();
        $this->em->persist(new ForgeRepository($project, 'github', '604', 'loupe-ac/loupe', ForgeRepositorySource::Hook));
        $reference = new ForgePullRequest($project, 'github', 'loupe-ac/loupe', 604);
        $this->em->persist($reference);
        $this->em->flush();

        $failure = $this->failure($reference);

        self::assertSame('no_installation', $failure->cause);
        self::assertTrue($failure->permanent);
        self::assertSame([], $this->requests);
    }

    private function tracked(string $path, int $installationId): ForgePullRequest
    {
        $project = $this->project();
        $this->em->persist(new GitHubInstallation($project, $installationId, 'loupe-ac', GitHubRepositorySelection::Selected));
        $this->em->persist(new ForgeRepository($project, 'github', (string) $installationId, $path, ForgeRepositorySource::Installation, (string) $installationId));
        $reference = new ForgePullRequest($project, 'github', $path, 604);
        $this->em->persist($reference);
        $this->em->flush();

        return $reference;
    }

    private function project(): Project
    {
        $user = new User(fullName: 'Riley', email: 'epic-merger-'.uniqid().'@example.com', password: 'hashed');
        $project = new Project($user, 'epic-merger-'.uniqid());
        $this->em->persist($user);
        $this->em->persist($project);

        return $project;
    }

    private function merger(?string $appId = '123456'): GitHubEpicBranchMerger
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($this->responses) ?? throw new \LogicException('Unexpected request '.$method.' '.$url);
        }, 'https://api.github.com');
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $pem));
        self::assertIsString($pem);

        $installations = self::getContainer()->get(GitHubPullRequestInstallations::class);
        self::assertInstanceOf(GitHubPullRequestInstallations::class, $installations);

        return new GitHubEpicBranchMerger(
            new GitHubAppApi($client, new GitHubAppConfiguration(null, null, null, null, $appId, $pem), new MockClock('@'.self::NOW)),
            $installations,
        );
    }

    private function failure(ForgePullRequest $reference, ?string $appId = '123456'): EpicBranchMergeFailed
    {
        try {
            $this->merger($appId)->merge($reference, 'epic/820', 'main');
        } catch (EpicBranchMergeFailed $e) {
            return $e;
        }

        self::fail('Expected EpicBranchMergeFailed.');
    }

    private function token(): MockResponse
    {
        return new MockResponse('{"token":"ghs_token"}', ['http_code' => 201]);
    }
}
