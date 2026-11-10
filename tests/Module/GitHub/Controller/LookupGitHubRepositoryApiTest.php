<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Controller;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\GitHub\Entity\GitHubInstallation;
use App\Module\GitHub\Entity\GitHubRepositorySelection;
use App\Module\GitHub\Service\GitHubAppApi;
use App\Module\GitHub\Service\GitHubAppConfiguration;
use App\Module\Project\Entity\Project;
use App\Tests\Support\ApiKeyCredential;
use App\Tests\Support\OAuthScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Cache\CacheInterface;

/** GET /api/v1/projects/{project}/github/repositories/status. */
final class LookupGitHubRepositoryApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private OAuthScenario $scenario;

    /** @var list<string> */
    private array $requests = [];

    /** @var list<MockResponse> */
    private array $responses = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->scenario = new OAuthScenario(static::getContainer());
        $cache = static::getContainer()->get(CacheInterface::class);
        self::assertInstanceOf(\Symfony\Component\Cache\Adapter\AdapterInterface::class, $cache);
        $cache->clear();
        $this->useApi();
    }

    public function test_a_request_with_no_token_is_unauthorized(): void
    {
        $this->client->request(Request::METHOD_GET, '/api/v1/projects/some-site/github/repositories/status?path=acme/api');

        self::assertResponseStatusCodeSame(401);
    }

    public function test_a_repository_the_app_reaches_and_no_project_holds_is_free(): void
    {
        [$owner, $project] = $this->projectOf('free');
        $this->responses = [$this->answer(['id' => 81_001]), $this->answer(['token' => 'ghs_t'], 201), $this->answer(['id' => 7_001, 'full_name' => 'acme/lookup-free'])];

        $this->call($owner, $project, 'acme/lookup-free');

        self::assertResponseIsSuccessful();
        self::assertSame(['path' => 'acme/lookup-free', 'status' => 'free'], $this->json());
        self::assertSame('https://api.github.com/repos/acme/lookup-free/installation', $this->requests[0]);
    }

    public function test_a_repository_the_project_holds_is_in_the_project_and_asks_no_one(): void
    {
        [$owner, $project] = $this->projectOf('mine');
        $this->hold($project, 7_002, 'acme/lookup-mine');

        $this->call($owner, $project, 'ACME/Lookup-Mine');

        self::assertSame('in-project', $this->json()['status']);
        self::assertSame([], $this->requests);
    }

    public function test_a_repository_another_project_holds_is_other_project_and_names_no_project(): void
    {
        [$owner, $project] = $this->projectOf('other');
        $this->hold($this->scenario->createProject($owner, 'Elsewhere'), 7_003, 'acme/lookup-other');
        $this->responses = [$this->answer(['id' => 81_003]), $this->answer(['token' => 'ghs_t'], 201), $this->answer(['id' => 7_003, 'full_name' => 'acme/lookup-other'])];

        $this->call($owner, $project, 'acme/lookup-other');

        self::assertSame(['path' => 'acme/lookup-other', 'status' => 'other-project'], $this->json());
    }

    public function test_a_repository_the_app_does_not_reach_is_not_shared(): void
    {
        [$owner, $project] = $this->projectOf('none');
        $this->responses = [$this->answer(['message' => 'Not Found'], 404)];

        $this->call($owner, $project, 'acme/lookup-none');

        self::assertSame('not-shared', $this->json()['status']);
    }

    public function test_the_answer_of_github_is_cached(): void
    {
        [$owner, $project] = $this->projectOf('cached');
        $this->responses = [$this->answer(['message' => 'Not Found'], 404)];

        $this->call($owner, $project, 'acme/lookup-cached');
        $this->call($owner, $project, 'acme/lookup-cached');

        self::assertSame('not-shared', $this->json()['status']);
        self::assertCount(1, $this->requests);
    }

    public function test_it_answers_bad_gateway_when_github_fails(): void
    {
        [$owner, $project] = $this->projectOf('down');
        $this->responses = [$this->answer([], 500)];

        $this->call($owner, $project, 'acme/lookup-down');

        self::assertResponseStatusCodeSame(502);
        self::assertSame('github_unavailable', $this->json()['code']);
    }

    public function test_it_refuses_a_path_that_is_not_owner_and_name(): void
    {
        [$owner, $project] = $this->projectOf('bad');

        $this->call($owner, $project, 'not a path');

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->requests);
    }

    public function test_it_does_not_serve_the_project_of_another_owner(): void
    {
        [, $project] = $this->projectOf('stranger');
        $stranger = $this->scenario->createUser('lookup-intruder@example.com');

        $this->call($stranger, $project, 'acme/lookup-stranger');

        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->requests);
    }

    /** @return array{User, Project} */
    private function projectOf(string $label): array
    {
        $owner = $this->scenario->createUser('lookup-'.$label.'@example.com');

        return [$owner, $this->scenario->createProject($owner, 'Lookup '.$label)];
    }

    private function hold(Project $project, int $repositoryId, string $path): void
    {
        $this->em()->persist(new GitHubInstallation(80_000 + $repositoryId, 'acme', GitHubRepositorySelection::Selected));
        $this->em()->persist(new ForgeRepository($project, 'github', (string) $repositoryId, $path, ForgeRepositorySource::Installation, (string) (80_000 + $repositoryId)));
        $this->em()->flush();
    }

    private function useApi(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $pem));
        self::assertIsString($pem);

        $http = new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->requests[] = $url;

            return array_shift($this->responses) ?? throw new \LogicException('Unexpected request '.$method.' '.$url);
        }, 'https://api.github.com');
        $configuration = new GitHubAppConfiguration(null, null, null, null, '123456', $pem);
        static::getContainer()->set(GitHubAppConfiguration::class, $configuration);
        static::getContainer()->set(GitHubAppApi::class, new GitHubAppApi($http, $configuration, new MockClock('2026-10-18 13:00:00')));
    }

    private function call(User $owner, Project $project, string $path): void
    {
        $this->client->request(Request::METHOD_GET, '/api/v1/projects/'.$project->id.'/github/repositories/status?path='.rawurlencode($path), server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.ApiKeyCredential::mint(static::getContainer(), $owner),
            'HTTP_ACCEPT' => 'application/json',
        ]);
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
