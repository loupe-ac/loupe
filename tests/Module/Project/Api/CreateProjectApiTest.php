<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Api;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\SiteOrigins;
use App\Tests\Support\AgentCredential;
use App\Tests\Support\OAuthScenario;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CreateProjectApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private OAuthScenario $scenario;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->scenario = new OAuthScenario(static::getContainer());
    }

    public function test_a_request_with_no_token_is_unauthorized(): void
    {
        $this->client->request(Request::METHOD_POST, '/api/projects', server: ['CONTENT_TYPE' => 'application/json'], content: '{"name":"Shop"}');

        self::assertResponseStatusCodeSame(401);
    }

    public function test_a_project_bound_token_is_refused_and_creates_nothing(): void
    {
        $owner = $this->scenario->createUser('create-project-bound@example.com');
        $project = $this->scenario->createProject($owner, 'Bound site');
        $token = AgentCredential::tokenFor(static::getContainer(), $owner, 'agent mcp', $project);

        $this->call($token, '{"name":"Shop"}');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['error' => 'credential_not_owner_wide'], $this->json());
        self::assertSame(0, $this->countNamed('Shop'));
    }

    public function test_an_agent_token_with_no_project_grant_is_refused_and_creates_nothing(): void
    {
        $owner = $this->scenario->createUser('create-project-agent-only@example.com');

        $this->call(AgentCredential::agentToken(static::getContainer(), $owner), '{"name":"Shop"}');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['error' => 'credential_not_owner_wide'], $this->json());
        self::assertSame(0, $this->countNamed('Shop'));
    }

    public function test_an_owner_wide_token_creates_a_project_with_the_lifecycle_binding(): void
    {
        $owner = $this->scenario->createUser('create-project-owner@example.com');
        $token = $this->ownerWideToken($owner);

        $this->call($token, '{"name":"Shop","domain":"https://shop.example.com","origins":["https://Shop.example.com/"],"remote":"git@github.com:acme/shop.git"}');

        self::assertResponseStatusCodeSame(201);
        $body = $this->json();
        self::assertSame('Shop', $body['name']);
        self::assertSame('shop', $body['slug']);
        self::assertSame('https://shop.example.com', $body['domain']);
        self::assertSame(['https://shop.example.com'], $body['allowedOrigins']);

        $project = static::getContainer()->get(ProjectRepository::class)->findOneByOwnerAndName($owner, 'Shop');
        self::assertInstanceOf(Project::class, $project);
        self::assertSame((string) $project->id, $body['id']);
        self::assertSame('https://shop.example.com', $project->domain);
        self::assertSame(['https://shop.example.com'], $project->allowedOrigins);
        self::assertSame(1, (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM workflow_bindings WHERE project_id = :id', ['id' => (string) $project->id]));
    }

    public function test_a_remote_alone_names_the_project_after_the_repository(): void
    {
        $owner = $this->scenario->createUser('create-project-remote@example.com');
        $token = $this->ownerWideToken($owner);

        $this->call($token, '{"remote":"https://github.com/acme/billing-api.git"}');

        self::assertResponseStatusCodeSame(201);
        self::assertSame('billing-api', $this->json()['name']);
    }

    public function test_a_second_project_with_the_same_name_is_a_conflict(): void
    {
        $owner = $this->scenario->createUser('create-project-twice@example.com');
        $token = $this->ownerWideToken($owner);

        $this->call($token, '{"name":"Shop"}');
        $this->call($token, '{"name":"Shop"}');

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'name_taken', 'slug' => 'shop'], $this->json());
        self::assertSame(1, $this->countNamed('Shop'));
    }

    public function test_a_name_that_differs_only_in_case_is_a_conflict_with_the_slug_of_the_existing_project(): void
    {
        $token = $this->ownerWideToken($this->scenario->createUser('create-project-case@example.com'));

        $this->call($token, '{"name":"Blog"}');
        $this->call($token, '{"name":"blog"}');

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'name_taken', 'slug' => 'blog'], $this->json());
    }

    public function test_the_domain_is_none_and_the_origins_are_empty_when_the_request_gives_neither(): void
    {
        $token = $this->ownerWideToken($this->scenario->createUser('create-project-plain@example.com'));

        $this->call($token, '{"name":"Shop"}');

        self::assertResponseStatusCodeSame(201);
        $body = $this->json();
        self::assertNull($body['domain']);
        self::assertSame([], $body['allowedOrigins']);
    }

    public function test_the_old_website_field_is_ignored(): void
    {
        $owner = $this->scenario->createUser('create-project-website@example.com');

        $this->call($this->ownerWideToken($owner), '{"name":"Shop","website":"https://shop.example.com"}');

        self::assertResponseStatusCodeSame(201);
        self::assertNull($this->json()['domain']);
        $project = static::getContainer()->get(ProjectRepository::class)->findOneByOwnerAndName($owner, 'Shop');
        self::assertInstanceOf(Project::class, $project);
        self::assertNull($project->domain);
    }

    public function test_a_bad_origin_creates_nothing_and_names_the_value(): void
    {
        $token = $this->ownerWideToken($this->scenario->createUser('create-project-badorigin@example.com'));

        $this->call($token, '{"name":"Shop","origins":["https://shop.example.com/checkout"]}');

        self::assertResponseStatusCodeSame(422);
        $violations = $this->json()['violations'] ?? null;
        self::assertIsArray($violations);
        self::assertCount(1, $violations);
        self::assertSame('origins[0]', $violations[0]['propertyPath']);
        self::assertSame(
            static::getContainer()->get(TranslatorInterface::class)->trans('project.api.error.origin_invalid', [], 'validators', 'en'),
            $violations[0]['title'],
        );
        self::assertStringContainsString('site origin', $violations[0]['title']);
        self::assertSame(0, $this->countNamed('Shop'));
    }

    public function test_a_non_string_origin_is_a_violation_and_creates_nothing(): void
    {
        $token = $this->ownerWideToken($this->scenario->createUser('create-project-numorigin@example.com'));

        $this->call($token, '{"name":"Shop","origins":[123]}');

        self::assertResponseStatusCodeSame(422);
        $violations = $this->json()['violations'] ?? null;
        self::assertIsArray($violations);
        self::assertSame('origins[0]', $violations[0]['propertyPath']);
        self::assertSame(0, $this->countNamed('Shop'));
    }

    public function test_copies_of_one_origin_count_once(): void
    {
        $owner = $this->scenario->createUser('create-project-copies@example.com');

        $this->call($this->ownerWideToken($owner), \sprintf('{"name":"Shop","origins":%s}', json_encode(array_fill(0, SiteOrigins::MAX + 1, 'https://shop.example.com'))));

        self::assertResponseStatusCodeSame(201);
        self::assertSame(['https://shop.example.com'], $this->json()['allowedOrigins']);
    }

    public function test_more_distinct_origins_than_the_cap_are_refused_and_create_nothing(): void
    {
        $token = $this->ownerWideToken($this->scenario->createUser('create-project-toomany@example.com'));
        $origins = array_map(static fn (int $n): string => 'https://site'.$n.'.example.com', range(1, SiteOrigins::MAX + 1));

        $this->call($token, \sprintf('{"name":"Shop","origins":%s}', json_encode($origins)));

        self::assertResponseStatusCodeSame(422);
        $violations = $this->json()['violations'] ?? null;
        self::assertIsArray($violations);
        self::assertCount(1, $violations);
        self::assertSame('origins', $violations[0]['propertyPath']);
        self::assertSame(0, $this->countNamed('Shop'));
    }

    public function test_blank_origins_are_skipped(): void
    {
        $token = $this->ownerWideToken($this->scenario->createUser('create-project-blankorigin@example.com'));

        $this->call($token, '{"name":"Shop","origins":["","https://shop.example.com"," "]}');

        self::assertResponseStatusCodeSame(201);
        self::assertSame(['https://shop.example.com'], $this->json()['allowedOrigins']);
    }

    public function test_another_owner_may_use_the_same_name(): void
    {
        $first = $this->scenario->createUser('create-project-first@example.com');
        $second = $this->scenario->createUser('create-project-second@example.com');

        $this->call($this->ownerWideToken($first), '{"name":"Shop"}');
        $this->call($this->ownerWideToken($second), '{"name":"Shop"}');

        self::assertResponseStatusCodeSame(201);
        self::assertSame(2, $this->countNamed('Shop'));
    }

    public function test_a_request_with_no_name_and_no_remote_is_refused(): void
    {
        $token = $this->ownerWideToken($this->scenario->createUser('create-project-empty@example.com'));

        $this->call($token, '{"domain":"https://shop.example.com"}');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'name_required'], $this->json());
    }

    public function test_a_name_with_no_letters_is_refused(): void
    {
        $token = $this->ownerWideToken($this->scenario->createUser('create-project-symbols@example.com'));

        $this->call($token, '{"name":"!!!"}');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'name_invalid'], $this->json());
    }

    public function test_a_remote_that_is_not_a_git_url_is_refused(): void
    {
        $token = $this->ownerWideToken($this->scenario->createUser('create-project-badremote@example.com'));

        $this->call($token, '{"name":"Shop","remote":"not a url"}');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->countNamed('Shop'));
    }

    public function test_the_limiter_throttles_a_flood(): void
    {
        $factory = new RateLimiterFactory(
            ['id' => 'agent_project_create', 'policy' => 'sliding_window', 'limit' => 2, 'interval' => '1 minute'],
            new InMemoryStorage(),
        );

        self::assertTrue($factory->create('credential')->consume()->isAccepted());
        self::assertTrue($factory->create('credential')->consume()->isAccepted());
        self::assertFalse($factory->create('credential')->consume()->isAccepted());
    }

    /** The scopes `loupe login` asks for: `mcp` with no project named covers every project of the owner. */
    private function ownerWideToken(User $owner): string
    {
        return AgentCredential::tokenFor(static::getContainer(), $owner, 'agent mcp');
    }

    private function call(string $token, string $body): void
    {
        $this->client->request(Request::METHOD_POST, '/api/projects', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: $body);
    }

    private function countNamed(string $name): int
    {
        return (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM projects WHERE name = :name', ['name' => $name]);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? $decoded : throw new \LogicException('the response is not a JSON object.');
    }
}
