<?php

declare(strict_types=1);

namespace App\Tests\Module\Readiness\Api;

use App\Module\Account\Entity\User;
use App\Module\Board\Service\BoardColumnSeeder;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Project\Entity\Project;
use App\Module\Readiness\Entity\DiscoveryRun;
use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Module\Readiness\Repository\DiscoveryRunRepository;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Entity\WorkflowBinding;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Security\CredentialRateLimitKey;
use App\Tests\Module\Readiness\ReadinessScenario;
use App\Tests\Support\AgentCredential;
use App\Tests\Support\ApiKeyCredential;
use App\Tests\Support\PageLink;
use App\Tests\Support\PublicSpec;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Uid\Uuid;

/** POST /api/v1/projects/{project}/discovery. */
final class StartDiscoveryApiTest extends WebTestCase
{
    use ReadinessScenario;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function test_an_api_key_starts_discovery_and_the_answer_names_the_run_and_the_card(): void
    {
        [$owner, $project] = $this->readyProject('discovery-api-start');
        $this->send($project, ApiKeyCredential::mint(static::getContainer(), $owner));

        self::assertResponseStatusCodeSame(201);
        $body = $this->json();
        self::assertSame(['runId', 'cardId', 'cardNumber', 'state', 'url'], array_keys($body));
        PublicSpec::assertMatches('DiscoveryStartResult', $body);
        self::assertSame('requested', $body['state']);

        $runs = $this->runs($project);
        self::assertCount(1, $runs);
        self::assertSame($body['runId'], (string) $runs[0]->id);
        self::assertSame($body['cardId'], (string) $runs[0]->card->id);
        self::assertSame($body['cardNumber'], $runs[0]->card->number);
        self::assertSame(DiscoveryRunState::Requested, $runs[0]->state);
        self::assertSame(Actor::ApiKey, $runs[0]->card->reporter);

        $match = PageLink::match(static::getContainer(), $body['url']);
        self::assertSame('app_project_workshop', $match['_route']);
        self::assertSame((string) $project->id, $match['id']);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringEndsWith('/api/v1/projects/'.$project->id.'/cards/'.$body['cardNumber'], $location);
        self::assertTrue($this->client->getResponse()->headers->has('RateLimit-Limit'));
    }

    public function test_a_cli_sign_in_starts_discovery_as_a_person(): void
    {
        [$owner, $project] = $this->readyProject('discovery-api-cli');
        $this->send($project, AgentCredential::tokenFor(static::getContainer(), $owner, 'public'));

        self::assertResponseStatusCodeSame(201);
        self::assertSame(Actor::Human, $this->runs($project)[0]->card->reporter);
    }

    public function test_a_second_start_while_a_run_is_open_conflicts_and_names_the_card(): void
    {
        [$owner, $project] = $this->readyProject('discovery-api-twice');
        $token = ApiKeyCredential::mint(static::getContainer(), $owner);
        $this->send($project, $token);
        self::assertResponseStatusCodeSame(201);
        $number = $this->json()['cardNumber'];

        $this->send($project, $token);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('discovery_running', $this->json()['code']);
        self::assertSame($number, $this->json()['cardNumber']);
        self::assertCount(1, $this->runs($project));
    }

    public function test_a_start_refused_by_its_route_limit_starts_no_run(): void
    {
        [$owner, $project] = $this->readyProject('discovery-api-limited');
        $token = ApiKeyCredential::mint(static::getContainer(), $owner);
        $this->client->disableReboot();
        $this->client->request(Request::METHOD_GET, '/api/v1/projects', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        $key = static::getContainer()->get(CredentialRateLimitKey::class)->forRequest(Request::create($this->url($project), Request::METHOD_POST));
        self::assertStringStartsWith('token:', $key);
        $factory = static::getContainer()->get('limiter.discovery_start');
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $factory);
        $limiter = $factory->create($key);
        $limiter->consume(100000);

        $this->send($project, $token);

        self::assertResponseStatusCodeSame(429);
        self::assertSame('rate_limited', $this->json()['code']);
        self::assertTrue($this->client->getResponse()->headers->has('Retry-After'));
        self::assertCount(0, $this->runs($project));

        $limiter->reset();
        $this->send($project, $token);

        self::assertResponseStatusCodeSame(201);
        self::assertCount(1, $this->runs($project));
    }

    public function test_a_project_with_no_live_bridge_conflicts(): void
    {
        [$owner, $project] = $this->readyProject('discovery-api-no-bridge', bridge: false);
        $this->send($project, ApiKeyCredential::mint(static::getContainer(), $owner));

        self::assertResponseStatusCodeSame(409);
        self::assertSame('no_bridge', $this->json()['code']);
        self::assertSame([], $this->runs($project));
    }

    public function test_a_project_with_no_workflow_conflicts(): void
    {
        [$owner, $project] = $this->readyProject('discovery-api-no-workflow', bound: false);
        $this->send($project, ApiKeyCredential::mint(static::getContainer(), $owner));

        self::assertResponseStatusCodeSame(409);
        self::assertSame('no_workflow', $this->json()['code']);
        self::assertSame([], $this->runs($project));
    }

    public function test_a_project_of_another_owner_reads_as_missing(): void
    {
        [, $project] = $this->readyProject('discovery-api-theirs');
        $other = $this->user('discovery-api-other@example.com');
        $this->send($project, ApiKeyCredential::mint(static::getContainer(), $other));

        self::assertResponseStatusCodeSame(404);
        self::assertSame('project_not_found', $this->json()['code']);
        self::assertSame([], $this->runs($project));
    }

    public function test_a_request_with_no_credential_is_refused(): void
    {
        [, $project] = $this->readyProject('discovery-api-anonymous');
        $this->send($project, null);

        self::assertResponseStatusCodeSame(401);
        self::assertSame([], $this->runs($project));
    }

    public function test_a_credential_without_the_public_scope_is_refused(): void
    {
        [$owner, $project] = $this->readyProject('discovery-api-scope');
        $this->send($project, AgentCredential::tokenFor(static::getContainer(), $owner, 'agent mcp'));

        self::assertResponseStatusCodeSame(403);
        self::assertSame('credential_not_allowed', $this->json()['code']);
        self::assertSame([], $this->runs($project));
    }

    public function test_a_get_answers_405(): void
    {
        [$owner, $project] = $this->readyProject('discovery-api-get');
        $this->client->request(Request::METHOD_GET, $this->url($project), server: ['HTTP_AUTHORIZATION' => 'Bearer '.ApiKeyCredential::mint(static::getContainer(), $owner)]);

        self::assertResponseStatusCodeSame(405);
    }

    /** @return array{User, Project} */
    private function readyProject(string $name, bool $bridge = true, bool $bound = true): array
    {
        $owner = $this->user($name.'@example.com');
        $project = $this->project($owner, $name);
        $seeder = static::getContainer()->get(BoardColumnSeeder::class);
        self::assertInstanceOf(BoardColumnSeeder::class, $seeder);
        $seeder->seed($project);
        if ($bound) {
            $shipped = static::getContainer()->get(ShippedTemplates::class);
            self::assertInstanceOf(ShippedTemplates::class, $shipped);
            $this->em()->persist(new WorkflowBinding($project, 'simple', '1', $shipped->source('simple')));
        }
        if ($bridge) {
            $live = new Bridge(AgentCredential::managed($this->em(), $owner, $owner->id), Uuid::v4(), [(string) $project->id], 'b4e39aa7', new \DateTimeImmutable());
            $live->capabilities = [Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_APP_PROMPTS];
            $this->em()->persist($live);
        }
        $this->em()->flush();

        return [$owner, $project];
    }

    private function url(Project $project): string
    {
        return '/api/v1/projects/'.$project->id.'/discovery';
    }

    private function send(Project $project, ?string $token): void
    {
        $this->client->request(Request::METHOD_POST, $this->url($project), server: null === $token ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
    }

    /** @return list<DiscoveryRun> */
    private function runs(Project $project): array
    {
        $this->em()->clear();
        $repository = static::getContainer()->get(DiscoveryRunRepository::class);
        self::assertInstanceOf(DiscoveryRunRepository::class, $repository);

        return array_values($repository->findBy(['project' => (string) $project->id]));
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
