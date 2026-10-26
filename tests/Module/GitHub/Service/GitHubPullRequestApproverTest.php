<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Service;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Service\PullRequestApprovers;
use App\Module\Forge\Service\PullRequestWriteFailed;
use App\Module\GitHub\Entity\GitHubInstallation;
use App\Module\GitHub\Entity\GitHubRepositorySelection;
use App\Module\GitHub\Service\GitHubAppApi;
use App\Module\GitHub\Service\GitHubAppConfiguration;
use App\Module\GitHub\Service\GitHubPullRequestApprover;
use App\Module\GitHub\Service\GitHubPullRequestInstallations;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GitHubPullRequestApproverTest extends KernelTestCase
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

    public function test_the_registry_hands_github_pull_requests_to_this_approver(): void
    {
        $approver = $this->approver();
        $approvers = new PullRequestApprovers([$approver]);

        self::assertSame($approver, $approvers->for('github'));
        self::assertNull($approvers->for('gitlab'));
    }

    public function test_a_repository_with_an_installation_can_be_approved_and_one_without_cannot(): void
    {
        self::assertTrue($this->approver()->canApprove($this->tracked('loupe-ac/loupe', 73_001)));

        $project = $this->project();
        $this->em->persist(new ForgeRepository($project, 'github', '604', 'loupe-ac/loupe', ForgeRepositorySource::Hook));
        $pullRequest = new ForgePullRequest($project, 'github', 'loupe-ac/loupe', 604);
        $this->em->persist($pullRequest);
        $this->em->flush();

        self::assertFalse($this->approver()->canApprove($pullRequest));
    }

    public function test_it_reads_the_head_from_github_past_the_stored_row(): void
    {
        $pullRequest = $this->tracked('Loupe-AC/Loupe.site', 73_020);
        $pullRequest->headSha = 'stale1';
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->answer(['state' => 'open', 'head' => ['sha' => 'new456']]),
            $this->answer(['state' => 'closed', 'head' => ['sha' => 'new456']]),
        ];

        $approver = $this->approver();

        self::assertSame('new456', $approver->openHead($pullRequest));
        self::assertNull($approver->openHead($pullRequest));
        self::assertSame('GET', $this->requests[1]['method']);
        self::assertSame('https://api.github.com/repos/Loupe-AC/Loupe.site/pulls/604', $this->requests[1]['url']);
    }

    public function test_a_head_read_without_a_commit_is_a_transient_failure(): void
    {
        $pullRequest = $this->tracked('loupe-ac/loupe', 73_021);
        $this->responses = [$this->answer(['token' => 'ghs_token'], 201), $this->answer(['state' => 'open'])];

        $failure = $this->failure(fn () => $this->approver()->openHead($pullRequest));

        self::assertSame('malformed_body', $failure->cause);
        self::assertFalse($failure->permanent);
    }

    public function test_it_posts_an_approval_of_the_exact_commit_and_returns_the_review_it_made(): void
    {
        $pullRequest = $this->tracked('Loupe-AC/Loupe.site', 73_002);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->answer(['id' => 99, 'node_id' => 'PRR_node', 'commit_id' => 'abc123', 'state' => 'APPROVED']),
        ];

        $approval = $this->approver()->approve($pullRequest, 'abc123', 'Approved by Loupe');

        self::assertSame('PRR_node', $approval->reviewId);
        self::assertSame('abc123', $approval->commitSha);
        self::assertSame('POST', $this->requests[1]['method']);
        self::assertSame('https://api.github.com/repos/Loupe-AC/Loupe.site/pulls/604/reviews', $this->requests[1]['url']);
        self::assertSame(['event' => 'APPROVE', 'commit_id' => 'abc123', 'body' => 'Approved by Loupe'], $this->json(1));
    }

    public function test_an_answer_without_a_review_id_is_a_transient_failure(): void
    {
        $pullRequest = $this->tracked('loupe-ac/loupe', 73_003);
        $this->responses = [$this->answer(['token' => 'ghs_token'], 201), $this->answer(['id' => 99])];

        $failure = $this->failure(fn () => $this->approver()->approve($pullRequest, 'abc123', ''));

        self::assertSame('malformed_body', $failure->cause);
        self::assertFalse($failure->permanent);
    }

    /** @return iterable<string, array{int, string, bool}> */
    public static function refusedApprovals(): iterable
    {
        yield '403' => [403, 'permission', true];
        yield '422, the commit is not part of the pull request' => [422, 'permission', true];
        yield '502' => [502, 'api_failed_http_status_502', false];
    }

    #[DataProvider('refusedApprovals')]
    public function test_a_refused_approval_names_its_cause(int $status, string $cause, bool $permanent): void
    {
        $pullRequest = $this->tracked('loupe-ac/loupe', 73_010 + $status);
        $this->responses = [$this->answer(['token' => 'ghs_token'], 201), $this->answer(['message' => 'No'], $status)];

        $failure = $this->failure(fn () => $this->approver()->approve($pullRequest, 'abc123', ''));

        self::assertSame($cause, $failure->cause);
        self::assertSame($permanent, $failure->permanent);
    }

    public function test_it_dismisses_a_review_with_the_graphql_mutation(): void
    {
        $pullRequest = $this->tracked('loupe-ac/loupe', 73_004);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->answer(['data' => ['dismissPullRequestReview' => ['pullRequestReview' => ['state' => 'DISMISSED']]]]),
        ];

        $this->approver()->dismiss($pullRequest, 'PRR_node', 'A person must review it');

        self::assertCount(2, $this->requests);
        self::assertSame('https://api.github.com/graphql', $this->requests[1]['url']);
        $body = $this->json(1);
        self::assertStringContainsString('dismissPullRequestReview(input:{pullRequestReviewId:$id,message:$message})', (string) $body['query']);
        self::assertSame(['id' => 'PRR_node', 'message' => 'A person must review it'], $body['variables']);
    }

    public function test_a_review_that_is_no_longer_standing_counts_as_dismissed(): void
    {
        $pullRequest = $this->tracked('loupe-ac/loupe', 73_005);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->answer(['data' => null, 'errors' => [['type' => 'UNPROCESSABLE', 'message' => 'Can not dismiss a dismissed pull request review']]]),
            $this->answer(['data' => ['node' => ['state' => 'DISMISSED']]]),
        ];

        $this->approver()->dismiss($pullRequest, 'PRR_node', 'A person must review it');

        self::assertCount(3, $this->requests);
    }

    public function test_a_refused_dismissal_of_a_standing_review_is_a_permanent_failure(): void
    {
        $pullRequest = $this->tracked('loupe-ac/loupe', 73_006);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->answer(['data' => null, 'errors' => [['type' => 'UNPROCESSABLE', 'message' => 'Not allowed']]]),
            $this->answer(['data' => ['node' => ['state' => 'APPROVED']]]),
        ];

        $failure = $this->failure(fn () => $this->approver()->dismiss($pullRequest, 'PRR_node', ''));

        self::assertSame('permission', $failure->cause);
        self::assertTrue($failure->permanent);
    }

    public function test_a_forbidden_dismissal_is_a_permanent_permission_failure(): void
    {
        $pullRequest = $this->tracked('loupe-ac/loupe', 73_007);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->answer(['data' => null, 'errors' => [['type' => 'FORBIDDEN', 'message' => 'Resource not accessible by integration']]]),
        ];

        $failure = $this->failure(fn () => $this->approver()->dismiss($pullRequest, 'PRR_node', ''));

        self::assertSame('permission', $failure->cause);
        self::assertTrue($failure->permanent);
    }

    public function test_a_graphql_rate_limit_is_a_transient_failure_with_a_fallback_delay(): void
    {
        $pullRequest = $this->tracked('loupe-ac/loupe', 73_008);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->answer(['data' => null, 'errors' => [['type' => 'RATE_LIMITED']]]),
        ];

        $failure = $this->failure(fn () => $this->approver()->dismiss($pullRequest, 'PRR_node', ''));

        self::assertSame('api_failed_rate_limited', $failure->cause);
        self::assertFalse($failure->permanent);
        self::assertSame(60, $failure->retryAfterSeconds);
    }

    public function test_a_repository_without_an_installation_is_a_permanent_failure(): void
    {
        $project = $this->project();
        $this->em->persist(new ForgeRepository($project, 'github', '604', 'loupe-ac/loupe', ForgeRepositorySource::Hook));
        $pullRequest = new ForgePullRequest($project, 'github', 'loupe-ac/loupe', 604);
        $this->em->persist($pullRequest);
        $this->em->flush();

        $failure = $this->failure(fn () => $this->approver()->approve($pullRequest, 'abc123', ''));

        self::assertSame('no_installation', $failure->cause);
        self::assertTrue($failure->permanent);
        self::assertSame([], $this->requests);
    }

    private function tracked(string $path, int $installationId): ForgePullRequest
    {
        $project = $this->project();
        $this->em->persist(new GitHubInstallation($project, $installationId, 'loupe-ac', GitHubRepositorySelection::Selected));
        $this->em->persist(new ForgeRepository($project, 'github', (string) $installationId, $path, ForgeRepositorySource::Installation, (string) $installationId));
        $pullRequest = new ForgePullRequest($project, 'github', $path, 604);
        $this->em->persist($pullRequest);
        $this->em->flush();

        return $pullRequest;
    }

    private function project(): Project
    {
        $user = new User(fullName: 'Riley', email: 'approver-'.uniqid().'@example.com', password: 'hashed');
        $project = new Project($user, 'approver-'.uniqid());
        $this->em->persist($user);
        $this->em->persist($project);

        return $project;
    }

    private function approver(): GitHubPullRequestApprover
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

        return new GitHubPullRequestApprover(
            new GitHubAppApi($client, new GitHubAppConfiguration(null, null, null, null, '123456', $pem), new MockClock('@'.self::NOW)),
            $installations,
        );
    }

    private function failure(\Closure $call): PullRequestWriteFailed
    {
        try {
            $call();
        } catch (PullRequestWriteFailed $e) {
            return $e;
        }

        self::fail('Expected PullRequestWriteFailed.');
    }

    /** @return array<string, mixed> */
    private function json(int $request): array
    {
        $body = $this->requests[$request]['options']['body'] ?? null;
        self::assertIsString($body);
        $decoded = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /* @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @param array<mixed> $body */
    private function answer(array $body, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['http_code' => $status]);
    }
}
