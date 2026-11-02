<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Api;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Tests\Support\AgentCredential;
use App\Tests\Support\ApiKeyCredential;
use App\Tests\Support\OAuthScenario;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/** POST /api/v1/projects, PATCH /api/v1/projects/{project}, and the old POST /api/projects beside the new one. */
final class ProjectWriteApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private OAuthScenario $scenario;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->scenario = new OAuthScenario(static::getContainer());
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    public function test_an_api_key_creates_a_project_and_the_answer_names_its_location(): void
    {
        $owner = $this->scenario->createUser('v1-create-key@example.com');

        $this->send(Request::METHOD_POST, '/api/v1/projects', ApiKeyCredential::mint(static::getContainer(), $owner), '{"name":"Shop","domain":"shop.example.com","origins":["https://Shop.example.com/"]}');

        self::assertResponseStatusCodeSame(201);
        $body = $this->json();
        self::assertSame('Shop', $body['name']);
        self::assertSame('shop', $body['slug']);
        self::assertSame('shop.example.com', $body['domain']);
        self::assertSame(['https://shop.example.com'], $body['allowedOrigins']);
        self::assertSame('english', $body['searchLanguage']);
        self::assertNull($body['description']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $body['createdAt']);
        self::assertStringEndsWith('/api/v1/projects/'.$body['id'], (string) $this->client->getResponse()->headers->get('Location'));
        self::assertTrue($this->client->getResponse()->headers->has('RateLimit-Limit'));
        self::assertSame(1, $this->countNamed('Shop'));
    }

    public function test_a_remote_alone_names_the_project_after_the_repository(): void
    {
        $token = ApiKeyCredential::mint(static::getContainer(), $this->scenario->createUser('v1-create-remote@example.com'));

        $this->send(Request::METHOD_POST, '/api/v1/projects', $token, '{"remote":"https://github.com/acme/billing-api.git"}');

        self::assertResponseStatusCodeSame(201);
        self::assertSame('billing-api', $this->json()['name']);
    }

    public function test_the_old_cli_grant_is_refused_on_the_new_path(): void
    {
        $owner = $this->scenario->createUser('v1-create-oldcli@example.com');

        $this->send(Request::METHOD_POST, '/api/v1/projects', AgentCredential::tokenFor(static::getContainer(), $owner, 'agent mcp'), '{"name":"Shop"}');

        self::assertResponseStatusCodeSame(403);
        self::assertSame('credential_not_allowed', $this->json()['code']);
        self::assertSame(0, $this->countNamed('Shop'));
    }

    public function test_a_request_with_no_credential_is_refused(): void
    {
        $this->send(Request::METHOD_POST, '/api/v1/projects', null, '{"name":"Shop"}');

        self::assertResponseStatusCodeSame(401);
        self::assertSame('invalid_credentials', $this->json()['code']);
    }

    public function test_a_second_project_with_the_same_name_is_a_conflict_that_names_the_slug(): void
    {
        $token = ApiKeyCredential::mint(static::getContainer(), $this->scenario->createUser('v1-create-twice@example.com'));

        $this->send(Request::METHOD_POST, '/api/v1/projects', $token, '{"name":"Shop"}');
        $this->send(Request::METHOD_POST, '/api/v1/projects', $token, '{"name":"Shop"}');

        self::assertResponseStatusCodeSame(409);
        $body = $this->json();
        self::assertSame('name_taken', $body['code']);
        self::assertSame('shop', $body['slug']);
        self::assertSame(1, $this->countNamed('Shop'));
    }

    public function test_a_request_with_no_name_and_no_remote_is_name_required(): void
    {
        $token = ApiKeyCredential::mint(static::getContainer(), $this->scenario->createUser('v1-create-empty@example.com'));

        $this->send(Request::METHOD_POST, '/api/v1/projects', $token, '{"domain":"shop.example.com"}');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('name_required', $this->json()['code']);
    }

    public function test_a_name_with_no_letters_is_name_invalid(): void
    {
        $token = ApiKeyCredential::mint(static::getContainer(), $this->scenario->createUser('v1-create-symbols@example.com'));

        $this->send(Request::METHOD_POST, '/api/v1/projects', $token, '{"name":"!!!"}');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('name_invalid', $this->json()['code']);
    }

    public function test_a_bad_origin_is_a_validation_problem_that_names_the_field(): void
    {
        $token = ApiKeyCredential::mint(static::getContainer(), $this->scenario->createUser('v1-create-origin@example.com'));

        $this->send(Request::METHOD_POST, '/api/v1/projects', $token, '{"name":"Shop","origins":["https://shop.example.com/checkout"]}');

        self::assertResponseStatusCodeSame(422);
        $body = $this->json();
        self::assertSame('validation_failed', $body['code']);
        self::assertSame('origins[0]', $body['errors'][0]['field']);
        self::assertSame(0, $this->countNamed('Shop'));
    }

    public function test_a_body_that_is_no_json_is_invalid_json(): void
    {
        $token = ApiKeyCredential::mint(static::getContainer(), $this->scenario->createUser('v1-create-badjson@example.com'));

        $this->send(Request::METHOD_POST, '/api/v1/projects', $token, '{not json');

        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_json', $this->json()['code']);
    }

    public function test_a_repeated_idempotency_key_answers_the_first_answer_and_creates_one_project(): void
    {
        $token = ApiKeyCredential::mint(static::getContainer(), $this->scenario->createUser('v1-create-idem@example.com'));

        $this->send(Request::METHOD_POST, '/api/v1/projects', $token, '{"name":"Shop"}', ['HTTP_IDEMPOTENCY_KEY' => 'create-shop-1']);
        $first = $this->json();
        $this->send(Request::METHOD_POST, '/api/v1/projects', $token, '{"name":"Shop"}', ['HTTP_IDEMPOTENCY_KEY' => 'create-shop-1']);

        self::assertResponseStatusCodeSame(201);
        self::assertSame($first, $this->json());
        self::assertSame(1, $this->countNamed('Shop'));
    }

    public function test_the_old_path_keeps_its_flat_errors_and_its_route_beside_the_new_one(): void
    {
        $owner = $this->scenario->createUser('legacy-create@example.com');
        $token = AgentCredential::tokenFor(static::getContainer(), $owner, 'agent mcp');

        $this->send(Request::METHOD_POST, '/api/projects', $token, '{}');
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'name_required'], $this->json());

        $this->send(Request::METHOD_POST, '/api/projects', $token, '{"name":"Shop"}');
        self::assertResponseStatusCodeSame(201);
        self::assertSame('shop', $this->json()['slug']);

        $this->send(Request::METHOD_POST, '/api/projects', $token, '{"name":"Shop"}');
        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'name_taken', 'slug' => 'shop'], $this->json());
    }

    public function test_a_patch_changes_the_name_and_the_slug_and_keeps_what_it_leaves_out(): void
    {
        $owner = $this->scenario->createUser('v1-patch-name@example.com');
        $project = $this->project($owner, 'Shop');
        $project->domain = 'shop.example.com';
        $this->em->flush();

        $this->send(Request::METHOD_PATCH, '/api/v1/projects/shop', ApiKeyCredential::mint(static::getContainer(), $owner), '{"name":"Storefront"}');

        self::assertResponseStatusCodeSame(200);
        $body = $this->json();
        self::assertSame((string) $project->id, $body['id']);
        self::assertSame('Storefront', $body['name']);
        self::assertSame('storefront', $body['slug']);
        self::assertSame('shop.example.com', $body['domain']);
        self::assertTrue($this->client->getResponse()->headers->has('RateLimit-Limit'));
        $this->em->clear();
        self::assertSame('Storefront', static::getContainer()->get(ProjectRepository::class)->find($project->id)?->name);
    }

    public function test_a_patch_sets_the_description_the_domain_the_language_and_the_origins_in_one_call(): void
    {
        $owner = $this->scenario->createUser('v1-patch-all@example.com');
        $project = $this->project($owner, 'Shop');

        $this->send(Request::METHOD_PATCH, '/api/v1/projects/'.$project->id, $this->publicToken($owner), '{"description":"The shop","domain":"shop.example.com","searchLanguage":"german","origins":["https://shop.example.com"]}');

        self::assertResponseStatusCodeSame(200);
        $body = $this->json();
        self::assertSame('The shop', $body['description']);
        self::assertSame('shop.example.com', $body['domain']);
        self::assertSame('german', $body['searchLanguage']);
        self::assertSame(['https://shop.example.com'], $body['allowedOrigins']);
        self::assertSame('Shop', $body['name']);
    }

    public function test_a_patch_clears_a_description_with_an_empty_string_and_the_origins_with_an_empty_list(): void
    {
        $owner = $this->scenario->createUser('v1-patch-clear@example.com');
        $project = $this->project($owner, 'Shop');
        $project->description = 'Old';
        $project->allowedOrigins = ['https://shop.example.com'];
        $this->em->flush();

        $this->send(Request::METHOD_PATCH, '/api/v1/projects/'.$project->id, $this->publicToken($owner), '{"description":"","origins":[]}');

        self::assertResponseStatusCodeSame(200);
        self::assertNull($this->json()['description']);
        self::assertSame([], $this->json()['allowedOrigins']);
    }

    public function test_a_refused_patch_changes_nothing(): void
    {
        $owner = $this->scenario->createUser('v1-patch-atomic@example.com');
        $project = $this->project($owner, 'Shop');

        $this->send(Request::METHOD_PATCH, '/api/v1/projects/'.$project->id, $this->publicToken($owner), '{"name":"Renamed","origins":["https://shop.example.com/path"]}');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('origins', $this->json()['errors'][0]['field']);
        $this->em->clear();
        self::assertSame('Shop', static::getContainer()->get(ProjectRepository::class)->find($project->id)?->name);
    }

    public function test_a_patch_to_a_name_that_is_taken_is_a_conflict(): void
    {
        $owner = $this->scenario->createUser('v1-patch-taken@example.com');
        $this->project($owner, 'Blog');
        $this->project($owner, 'Shop');

        $this->send(Request::METHOD_PATCH, '/api/v1/projects/shop', $this->publicToken($owner), '{"name":"Blog"}');

        self::assertResponseStatusCodeSame(409);
        self::assertSame('name_taken', $this->json()['code']);
        self::assertSame('blog', $this->json()['slug']);
    }

    public function test_a_patch_to_a_name_that_gives_a_taken_slug_is_a_slug_conflict(): void
    {
        $owner = $this->scenario->createUser('v1-patch-slug@example.com');
        $this->project($owner, 'My Blog');
        $this->project($owner, 'Shop');

        $this->send(Request::METHOD_PATCH, '/api/v1/projects/shop', $this->publicToken($owner), '{"name":"my-blog"}');

        self::assertResponseStatusCodeSame(409);
        self::assertSame('slug_taken', $this->json()['code']);
    }

    public function test_a_patch_with_a_blank_name_is_a_validation_problem(): void
    {
        $owner = $this->scenario->createUser('v1-patch-blank@example.com');
        $project = $this->project($owner, 'Shop');

        $this->send(Request::METHOD_PATCH, '/api/v1/projects/'.$project->id, $this->publicToken($owner), '{"name":"  "}');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('validation_failed', $this->json()['code']);
        self::assertSame(['field' => 'name', 'code' => 'name_blank', 'message' => 'A project name must not be blank.'], $this->json()['errors'][0]);
    }

    public function test_a_patch_with_an_unknown_language_is_a_validation_problem(): void
    {
        $owner = $this->scenario->createUser('v1-patch-language@example.com');
        $project = $this->project($owner, 'Shop');

        $this->send(Request::METHOD_PATCH, '/api/v1/projects/'.$project->id, $this->publicToken($owner), '{"searchLanguage":"klingon"}');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('validation_failed', $this->json()['code']);
        self::assertSame('searchLanguage', $this->json()['errors'][0]['field']);
    }

    public function test_a_patch_of_a_project_of_another_owner_is_not_found_and_changes_nothing(): void
    {
        $owner = $this->scenario->createUser('v1-patch-owner@example.com');
        $foreign = $this->project($this->scenario->createUser('v1-patch-foreign@example.com'), 'Foreign');

        $this->send(Request::METHOD_PATCH, '/api/v1/projects/'.$foreign->id, $this->publicToken($owner), '{"name":"Taken over"}');

        self::assertResponseStatusCodeSame(404);
        self::assertSame('project_not_found', $this->json()['code']);
        $this->em->clear();
        self::assertSame('Foreign', static::getContainer()->get(ProjectRepository::class)->find($foreign->id)?->name);
    }

    public function test_a_patch_with_the_old_cli_grant_is_refused(): void
    {
        $owner = $this->scenario->createUser('v1-patch-oldcli@example.com');
        $project = $this->project($owner, 'Shop');

        $this->send(Request::METHOD_PATCH, '/api/v1/projects/'.$project->id, AgentCredential::tokenFor(static::getContainer(), $owner, 'agent mcp'), '{"description":"x"}');

        self::assertResponseStatusCodeSame(403);
        self::assertSame('credential_not_allowed', $this->json()['code']);
    }

    public function test_a_patch_with_a_form_body_is_unsupported_media_type(): void
    {
        $owner = $this->scenario->createUser('v1-patch-media@example.com');
        $project = $this->project($owner, 'Shop');

        $this->client->request(Request::METHOD_PATCH, '/api/v1/projects/'.$project->id, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->publicToken($owner), 'CONTENT_TYPE' => 'text/plain'], content: 'name=x');

        self::assertResponseStatusCodeSame(415);
        self::assertSame('unsupported_media_type', $this->json()['code']);
    }

    private function project(User $owner, string $name): Project
    {
        $project = new Project($owner, $name);
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }

    private function publicToken(User $owner): string
    {
        return ApiKeyCredential::mint(static::getContainer(), $owner);
    }

    /** @param array<string, string> $server */
    private function send(string $method, string $url, ?string $token, string $body, array $server = []): void
    {
        $this->client->request($method, $url, server: $server + ['CONTENT_TYPE' => 'application/json'] + (null === $token ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$token]), content: $body);
    }

    private function countNamed(string $name): int
    {
        return (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM projects WHERE name = :name', ['name' => $name]);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
