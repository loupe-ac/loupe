<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Controller;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\GitHub\Entity\GitHubInstallation;
use App\Module\GitHub\Entity\GitHubRepositorySelection;
use App\Module\GitHub\Repository\GitHubInstallationRepository;
use App\Module\GitHub\Service\GitHubAppApi;
use App\Module\GitHub\Service\GitHubAppConfiguration;
use App\Module\GitHub\Service\GitHubGitTokenIssuer;
use App\Module\GitHub\Service\GitHubGitTokenRefused;
use App\Module\Project\Entity\Project;
use App\Tests\Support\AgentCredential;
use App\Tests\Support\OAuthScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;

final class IssueGitTokenApiTest extends WebTestCase
{
    private const int INSTALLATION = 71_001;
    private const int REPOSITORY = 9_001;

    private KernelBrowser $client;
    private OAuthScenario $scenario;

    /** @var list<array{method: string, url: string, body: string}> */
    private array $requests = [];

    /** @var list<MockResponse> */
    private array $responses = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->scenario = new OAuthScenario(static::getContainer());
    }

    public function test_a_request_with_no_token_is_unauthorized(): void
    {
        $this->client->request(Request::METHOD_POST, '/api/projects/some-site/git-token');

        self::assertResponseStatusCodeSame(401);
    }

    public function test_a_widget_token_is_refused(): void
    {
        $owner = $this->scenario->createUser('git-token-widget@example.com');
        $project = $this->scenario->createProject($owner, 'Widget site');

        $this->call(AgentCredential::tokenFor(static::getContainer(), $owner, 'site-review', $project), (string) $project->id);

        self::assertResponseStatusCodeSame(403);
    }

    public function test_it_issues_a_token_limited_to_the_repository_of_the_project(): void
    {
        [$owner, $project] = $this->managedProject('issue', ['contents' => 'write', 'pull_requests' => 'write', 'workflows' => 'write']);
        $this->responses[] = $this->answer(['token' => 'ghs_scoped', 'expires_at' => '2026-10-18T14:00:00Z'], 201);

        $this->call(AgentCredential::agentToken(static::getContainer(), $owner), (string) $project->id);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(['token' => 'ghs_scoped', 'expiresAt' => '2026-10-18T14:00:00+00:00'], $this->json());
        self::assertCount(2, $this->requests);
        self::assertSame('https://api.github.com/app/installations/'.self::INSTALLATION.'/access_tokens', $this->requests[1]['url']);
        self::assertSame(
            '{"repository_ids":['.self::REPOSITORY.'],"permissions":{"contents":"write","pull_requests":"write","workflows":"write"}}',
            $this->requests[1]['body'],
        );
    }

    public function test_it_leaves_out_the_workflows_grant_when_the_installation_lacks_it(): void
    {
        [$owner, $project] = $this->managedProject('no-workflows', ['contents' => 'write', 'pull_requests' => 'write']);
        $this->responses[] = $this->answer(['token' => 'ghs_scoped', 'expires_at' => '2026-10-18T14:00:00Z'], 201);

        $this->call(AgentCredential::agentToken(static::getContainer(), $owner), $project->slug ?? '');

        self::assertResponseStatusCodeSame(201);
        self::assertStringContainsString('"permissions":{"contents":"write","pull_requests":"write"}', $this->requests[1]['body']);
    }

    public function test_it_refuses_when_the_project_turned_the_setting_off(): void
    {
        [$owner, $project] = $this->managedProject('off', ['contents' => 'write', 'pull_requests' => 'write']);
        $project->gitIdentityManaged = false;
        $this->em()->flush();

        $this->call(AgentCredential::agentToken(static::getContainer(), $owner), (string) $project->id);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('git_identity_not_managed', $this->json()['error']);
        self::assertSame([], $this->requests);
    }

    public function test_it_names_the_missing_grants(): void
    {
        [$owner, $project] = $this->managedProject('read-only', ['contents' => 'read', 'pull_requests' => 'write']);

        $this->call(AgentCredential::agentToken(static::getContainer(), $owner), (string) $project->id);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'missing_grant', 'missing' => ['contents']], $this->json());
        self::assertCount(1, $this->requests);
    }

    public function test_it_refuses_a_project_with_no_installation(): void
    {
        $owner = $this->scenario->createUser('git-token-bare@example.com');
        $project = $this->scenario->createProject($owner, 'Bare site');

        $this->call(AgentCredential::agentToken(static::getContainer(), $owner), (string) $project->id);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('no_installation', $this->json()['error']);
        self::assertSame([], $this->requests);
    }

    public function test_it_refuses_a_suspended_installation(): void
    {
        [$owner, $project] = $this->managedProject('suspended', ['contents' => 'write', 'pull_requests' => 'write']);
        $installation = static::getContainer()->get(GitHubInstallationRepository::class)->findOneByInstallationId(self::INSTALLATION);
        self::assertInstanceOf(GitHubInstallation::class, $installation);
        $installation->suspendedAt = new \DateTimeImmutable();
        $this->em()->flush();

        $this->call(AgentCredential::agentToken(static::getContainer(), $owner), (string) $project->id);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('installation_suspended', $this->json()['error']);
    }

    public function test_it_serves_the_repository_of_the_project_and_never_the_one_of_another_project(): void
    {
        [$owner, $project] = $this->managedProject('mine', ['contents' => 'write', 'pull_requests' => 'write']);
        $other = $this->scenario->createProject($owner, 'Other site');
        $this->installation($other, 71_002, 9_002);

        $this->responses[] = $this->answer(['token' => 'ghs_scoped', 'expires_at' => '2026-10-18T14:00:00Z'], 201);
        $this->call(AgentCredential::agentToken(static::getContainer(), $owner), (string) $project->id);

        self::assertResponseStatusCodeSame(201);
        self::assertStringContainsString('"repository_ids":['.self::REPOSITORY.']', $this->requests[1]['body']);
        self::assertStringNotContainsString('9002', $this->requests[1]['body']);
    }

    public function test_it_serves_the_repository_the_worker_names_when_the_project_holds_several(): void
    {
        [$owner, $project] = $this->managedProject('several', ['contents' => 'write', 'pull_requests' => 'write']);
        $this->installation($project, self::INSTALLATION, 9_003, newInstallation: false);

        $this->responses[] = $this->answer(['token' => 'ghs_scoped', 'expires_at' => '2026-10-18T14:00:00Z'], 201);
        $this->call(AgentCredential::agentToken(static::getContainer(), $owner), (string) $project->id, 'ACME/site-9003');

        self::assertResponseStatusCodeSame(201);
        self::assertStringContainsString('"repository_ids":[9003]', $this->requests[1]['body']);
    }

    public function test_it_refuses_a_repository_the_project_does_not_hold(): void
    {
        [$owner, $project] = $this->managedProject('foreign', ['contents' => 'write', 'pull_requests' => 'write']);
        $other = $this->scenario->createProject($owner, 'Foreign site');
        $this->installation($other, 71_004, 9_004);

        $this->call(AgentCredential::agentToken(static::getContainer(), $owner), (string) $project->id, 'acme/site-9004');

        self::assertResponseStatusCodeSame(409);
        self::assertSame('no_repository', $this->json()['error']);
        self::assertSame([], $this->requests);
    }

    public function test_it_refuses_a_request_that_names_no_repository(): void
    {
        [$owner, $project] = $this->managedProject('unnamed', ['contents' => 'write', 'pull_requests' => 'write']);

        $this->call(AgentCredential::agentToken(static::getContainer(), $owner), (string) $project->id, null);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->requests);
    }

    public function test_it_does_not_serve_the_project_of_another_owner(): void
    {
        [, $project] = $this->managedProject('owned', ['contents' => 'write', 'pull_requests' => 'write']);
        $stranger = $this->scenario->createUser('git-token-stranger@example.com');

        $this->call(AgentCredential::agentToken(static::getContainer(), $stranger), (string) $project->id);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->requests);
    }

    public function test_a_token_bound_to_one_project_gets_no_token_for_another_project_of_its_owner(): void
    {
        [$owner, $project] = $this->managedProject('bound', ['contents' => 'write', 'pull_requests' => 'write']);
        $bound = $this->scenario->createProject($owner, 'Bound site');

        $this->call(AgentCredential::tokenFor(static::getContainer(), $owner, 'agent mcp', $bound), (string) $project->id);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->requests);
    }

    public function test_a_token_bound_to_the_project_gets_its_token(): void
    {
        [$owner, $project] = $this->managedProject('boundsame', ['contents' => 'write', 'pull_requests' => 'write']);
        $this->responses[] = $this->answer(['token' => 'ghs_scoped', 'expires_at' => '2026-10-18T14:00:00Z'], 201);

        $this->call(AgentCredential::tokenFor(static::getContainer(), $owner, 'agent mcp', $project), (string) $project->id);

        self::assertResponseStatusCodeSame(201);
    }

    public function test_the_project_can_push_when_a_later_installation_grants_what_the_first_lacks(): void
    {
        [, $project] = $this->managedProject('later', ['contents' => 'read', 'pull_requests' => 'write']);
        $this->installation($project, 71_005, 9_005);
        $this->responses = [$this->answer([
            ['id' => self::INSTALLATION, 'account' => ['login' => 'acme'], 'permissions' => ['contents' => 'read', 'pull_requests' => 'write']],
            ['id' => 71_005, 'account' => ['login' => 'acme'], 'permissions' => ['contents' => 'write', 'pull_requests' => 'write']],
        ])];

        self::assertNull($this->issuer()->refusal($project));
    }

    public function test_the_project_cannot_push_when_no_installation_grants_the_push(): void
    {
        [, $project] = $this->managedProject('none', ['contents' => 'read', 'pull_requests' => 'write']);

        $refusal = $this->issuer()->refusal($project);

        self::assertNotNull($refusal);
        self::assertSame(GitHubGitTokenRefused::MISSING_GRANT, $refusal->cause);
    }

    public function test_it_answers_bad_gateway_when_github_fails(): void
    {
        [$owner, $project] = $this->managedProject('down', ['contents' => 'write', 'pull_requests' => 'write']);
        $this->responses[] = $this->answer([], 500);

        $this->call(AgentCredential::agentToken(static::getContainer(), $owner), (string) $project->id);

        self::assertResponseStatusCodeSame(502);
        self::assertSame('github_unavailable', $this->json()['error']);
    }

    /**
     * @param array<string, string> $permissions
     *
     * @return array{\App\Module\Account\Entity\User, Project}
     */
    private function managedProject(string $label, array $permissions): array
    {
        $owner = $this->scenario->createUser('git-token-'.$label.'@example.com');
        $project = $this->scenario->createProject($owner, 'Site '.$label);
        $this->installation($project, self::INSTALLATION, self::REPOSITORY);
        $this->responses = [$this->answer([['id' => self::INSTALLATION, 'account' => ['login' => 'acme'], 'permissions' => $permissions]])];
        $this->useApi();

        return [$owner, $project];
    }

    private function issuer(): GitHubGitTokenIssuer
    {
        $issuer = static::getContainer()->get(GitHubGitTokenIssuer::class);
        self::assertInstanceOf(GitHubGitTokenIssuer::class, $issuer);

        return $issuer;
    }

    private function installation(Project $project, int $installationId, int $repositoryId, bool $newInstallation = true): void
    {
        if ($newInstallation) {
            $this->em()->persist(new GitHubInstallation($project, $installationId, 'acme', GitHubRepositorySelection::Selected));
        }
        $this->em()->persist(new ForgeRepository($project, 'github', (string) $repositoryId, 'acme/site-'.$repositoryId, ForgeRepositorySource::Installation, (string) $installationId));
        $this->em()->flush();
    }

    private function useApi(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $pem));
        self::assertIsString($pem);

        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'body' => (string) ($options['body'] ?? '')];

            return array_shift($this->responses) ?? throw new \LogicException('Unexpected request '.$method.' '.$url);
        }, 'https://api.github.com');
        static::getContainer()->set(GitHubAppApi::class, new GitHubAppApi(
            $http,
            new GitHubAppConfiguration(null, null, null, null, '123456', $pem),
            new MockClock('2026-10-18 13:00:00'),
        ));
    }

    /** @param ?string $repository the repository to ask for, or null to send an empty object */
    private function call(string $token, string $handle, ?string $repository = 'acme/site-'.self::REPOSITORY): void
    {
        $this->client->request(Request::METHOD_POST, '/api/projects/'.$handle.'/git-token', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ], content: null === $repository ? '{}' : json_encode(['repository' => $repository], \JSON_THROW_ON_ERROR));
    }

    /** @param array<mixed> $body */
    private function answer(array $body, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['http_code' => $status]);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? $decoded : throw new \LogicException('the response is not a JSON object.');
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
