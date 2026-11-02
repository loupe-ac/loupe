<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Api;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Tests\Support\AgentCredential;
use App\Tests\Support\ApiKeyCredential;
use App\Tests\Support\OAuthScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/** GET /api/v1/projects and GET /api/v1/projects/{project}. */
final class ProjectReadApiTest extends WebTestCase
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

    public function test_a_list_holds_the_projects_of_the_owner_oldest_first_in_the_contract_shape(): void
    {
        $owner = $this->scenario->createUser('list-order@example.com');
        $this->project($owner, 'Second', '2026-02-01 10:00:00');
        $first = $this->project($owner, 'First', '2026-01-01 10:00:00');
        $this->project($this->scenario->createUser('list-other@example.com'), 'Foreign', '2026-01-01 09:00:00');
        $first->description = 'Shop site';
        $first->domain = 'shop.example.com';
        $first->allowedOrigins = ['https://shop.example.com'];
        $this->em->flush();

        $this->get('/api/v1/projects', $this->publicToken($owner));

        self::assertResponseStatusCodeSame(200);
        $body = $this->json();
        self::assertSame(['First', 'Second'], array_column($body['items'], 'name'));
        self::assertNull($body['next']);
        self::assertSame([
            'id' => (string) $first->id,
            'slug' => 'first',
            'name' => 'First',
            'description' => 'Shop site',
            'domain' => 'shop.example.com',
            'searchLanguage' => 'english',
            'allowedOrigins' => ['https://shop.example.com'],
            'createdAt' => '2026-01-01T10:00:00+00:00',
        ], $body['items'][0]);
    }

    public function test_a_list_can_run_newest_first(): void
    {
        $owner = $this->scenario->createUser('list-newest@example.com');
        $this->project($owner, 'Old', '2026-01-01 10:00:00');
        $this->project($owner, 'New', '2026-02-01 10:00:00');

        $this->get('/api/v1/projects?sort=-createdAt', $this->publicToken($owner));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['New', 'Old'], array_column($this->json()['items'], 'name'));
    }

    public function test_an_unknown_sort_is_a_validation_problem(): void
    {
        $this->get('/api/v1/projects?sort=name', $this->publicToken($this->scenario->createUser('list-badsort@example.com')));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('validation_failed', $this->json()['code']);
        self::assertSame('sort', $this->json()['errors'][0]['field']);
    }

    public function test_a_page_names_the_next_page_in_the_body_and_in_the_link_header_and_the_walk_ends(): void
    {
        $owner = $this->scenario->createUser('list-pages@example.com');
        $this->project($owner, 'One', '2026-01-01 10:00:00');
        $this->project($owner, 'Two', '2026-01-01 10:00:00');
        $this->project($owner, 'Three', '2026-01-03 10:00:00');
        $token = $this->publicToken($owner);

        $seen = [];
        $url = '/api/v1/projects?limit=1';
        for ($page = 0; $page < 3; ++$page) {
            $this->get($url, $token);
            self::assertResponseStatusCodeSame(200);
            $body = $this->json();
            self::assertCount(1, $body['items']);
            $seen[] = $body['items'][0]['name'];
            if (2 > $page) {
                self::assertIsString($body['next']);
                self::assertSame(\sprintf('<%s>; rel="next"', $body['next']), $this->client->getResponse()->headers->get('Link'));
                $url = (string) parse_url($body['next'], \PHP_URL_PATH).'?'.parse_url($body['next'], \PHP_URL_QUERY);
            }
        }

        self::assertNull($body['next']);
        self::assertFalse($this->client->getResponse()->headers->has('Link'));
        self::assertEqualsCanonicalizing(['One', 'Two', 'Three'], $seen);
        self::assertSame('Three', $seen[2]);
    }

    public function test_a_broken_cursor_is_refused(): void
    {
        $this->get('/api/v1/projects?cursor=nonsense', $this->publicToken($this->scenario->createUser('list-badcursor@example.com')));

        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_cursor', $this->json()['code']);
    }

    public function test_a_cursor_made_for_another_sort_is_refused(): void
    {
        $owner = $this->scenario->createUser('list-cursorsort@example.com');
        $this->project($owner, 'One', '2026-01-01 10:00:00');
        $this->project($owner, 'Two', '2026-01-02 10:00:00');
        $token = $this->publicToken($owner);

        $this->get('/api/v1/projects?limit=1', $token);
        $cursor = Request::create($this->json()['next'])->query->getString('cursor');
        $this->get('/api/v1/projects?sort=-createdAt&cursor='.$cursor, $token);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_cursor', $this->json()['code']);
    }

    public function test_a_limit_out_of_range_is_a_validation_problem(): void
    {
        $this->get('/api/v1/projects?limit=0', $this->publicToken($this->scenario->createUser('list-limit@example.com')));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('limit', $this->json()['errors'][0]['field']);
    }

    public function test_an_api_key_lists_the_projects_of_its_owner(): void
    {
        $owner = $this->scenario->createUser('list-key@example.com');
        $this->project($owner, 'Keyed', '2026-01-01 10:00:00');

        $this->get('/api/v1/projects', ApiKeyCredential::mint(static::getContainer(), $owner));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Keyed'], array_column($this->json()['items'], 'name'));
    }

    public function test_a_list_answers_the_budget_headers(): void
    {
        $this->get('/api/v1/projects', $this->publicToken($this->scenario->createUser('list-budget@example.com')));

        $headers = $this->client->getResponse()->headers;
        self::assertTrue($headers->has('RateLimit-Limit'));
        self::assertTrue($headers->has('RateLimit-Remaining'));
        self::assertTrue($headers->has('RateLimit-Reset'));
    }

    public function test_a_request_with_no_credential_is_refused_as_problem_json(): void
    {
        $this->get('/api/v1/projects', null);

        self::assertResponseStatusCodeSame(401);
        self::assertSame('application/problem+json', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame('invalid_credentials', $this->json()['code']);
    }

    public function test_the_old_cli_grant_has_no_public_scope_and_is_refused_as_problem_json(): void
    {
        $owner = $this->scenario->createUser('list-oldcli@example.com');

        $this->get('/api/v1/projects', AgentCredential::tokenFor(static::getContainer(), $owner, 'agent mcp'));

        self::assertResponseStatusCodeSame(403);
        self::assertSame('application/problem+json', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame('credential_not_allowed', $this->json()['code']);
    }

    public function test_an_oauth_token_with_the_public_scope_lists_the_projects_and_one_with_only_the_mcp_scope_is_refused(): void
    {
        $owner = $this->scenario->createUser('list-oauth-public@example.com');
        $this->project($owner, 'Signed in', '2026-01-01 10:00:00');

        $this->get('/api/v1/projects', AgentCredential::tokenFor(static::getContainer(), $owner, 'public'));
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Signed in'], array_column($this->json()['items'], 'name'));

        $this->get('/api/v1/projects', AgentCredential::tokenFor(static::getContainer(), $owner, 'mcp'));
        self::assertResponseStatusCodeSame(403);
        self::assertSame('credential_not_allowed', $this->json()['code']);
    }

    public function test_a_widget_token_is_refused(): void
    {
        $owner = $this->scenario->createUser('list-widget@example.com');
        $project = $this->scenario->createProject($owner, 'Site');

        $this->get('/api/v1/projects', AgentCredential::tokenFor(static::getContainer(), $owner, 'site-review', $project));

        self::assertResponseStatusCodeSame(403);
        self::assertSame('credential_not_allowed', $this->json()['code']);
    }

    public function test_a_project_is_read_by_id_and_by_slug(): void
    {
        $owner = $this->scenario->createUser('show-handles@example.com');
        $project = $this->project($owner, 'Shop Front', '2026-01-01 10:00:00');
        $token = $this->publicToken($owner);

        $this->get('/api/v1/projects/'.$project->id, $token);
        self::assertResponseStatusCodeSame(200);
        $byId = $this->json();

        $this->get('/api/v1/projects/shop-front', $token);
        self::assertResponseStatusCodeSame(200);

        self::assertSame($byId, $this->json());
        self::assertSame((string) $project->id, $byId['id']);
        self::assertSame('shop-front', $byId['slug']);
        self::assertTrue($this->client->getResponse()->headers->has('RateLimit-Limit'));
    }

    public function test_a_project_of_another_owner_reads_the_same_as_a_missing_one(): void
    {
        $owner = $this->scenario->createUser('show-owner@example.com');
        $foreign = $this->project($this->scenario->createUser('show-foreign@example.com'), 'Foreign', '2026-01-01 10:00:00');
        $token = $this->publicToken($owner);

        $this->get('/api/v1/projects/'.$foreign->id, $token);
        $notYours = $this->client->getResponse()->getContent();
        self::assertResponseStatusCodeSame(404);
        self::assertSame('project_not_found', $this->json()['code']);
        self::assertSame('application/problem+json', $this->client->getResponse()->headers->get('Content-Type'));

        $this->get('/api/v1/projects/no-such-project', $token);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('project_not_found', $this->json()['code']);
        self::assertSame($this->withoutDetail((string) $notYours), $this->withoutDetail((string) $this->client->getResponse()->getContent()));
    }

    public function test_a_wrong_method_on_a_project_answers_405(): void
    {
        $owner = $this->scenario->createUser('show-method@example.com');
        $project = $this->project($owner, 'Shop', '2026-01-01 10:00:00');

        $this->client->request(Request::METHOD_DELETE, '/api/v1/projects/'.$project->id, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->publicToken($owner)]);

        self::assertResponseStatusCodeSame(405);
        self::assertSame('method_not_allowed', $this->json()['code']);
    }

    private function project(User $owner, string $name, string $createdAt): Project
    {
        $project = new Project($owner, $name, null, new \DateTimeImmutable($createdAt));
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }

    private function publicToken(User $owner): string
    {
        return ApiKeyCredential::mint(static::getContainer(), $owner);
    }

    private function get(string $url, ?string $token): void
    {
        $this->client->request(Request::METHOD_GET, $url, server: null === $token ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
    }

    private function withoutDetail(string $body): string
    {
        $decoded = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        unset($decoded['detail']);

        return json_encode($decoded, \JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
