<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth\Controller;

use App\Tests\Module\OAuth\PreviewTokenScenario;
use App\Tests\Support\AgentCredential;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Uid\Uuid;

final class PreviewTokenApiTest extends WebTestCase
{
    use PreviewTokenScenario;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->setUpScenario();
    }

    /** @return iterable<string, array{string, string}> */
    public static function routes(): iterable
    {
        yield 'mint' => [Request::METHOD_POST, '/api/projects/some-site/preview-tokens'];
        yield 'revoke' => [Request::METHOD_DELETE, '/api/projects/some-site/preview-tokens/pr-12'];
    }

    #[DataProvider('routes')]
    public function test_a_request_with_no_token_is_unauthorized(string $method, string $path): void
    {
        $this->client->request($method, $path, server: ['CONTENT_TYPE' => 'application/json'], content: '{"label":"pr-12"}');

        self::assertResponseStatusCodeSame(401);
    }

    #[DataProvider('routes')]
    public function test_a_widget_token_is_refused(string $method, string $path): void
    {
        $token = AgentCredential::tokenFor(static::getContainer(), $this->owner, 'site-review', $this->project);

        $this->call($method, $path, $token, '{"label":"pr-12"}');

        self::assertResponseStatusCodeSame(403);
    }

    public function test_a_mint_returns_a_token_that_the_widget_endpoints_accept(): void
    {
        $agent = AgentCredential::agentToken(static::getContainer(), $this->owner);

        $this->call(Request::METHOD_POST, $this->mintPath((string) $this->project->id), $agent, '{"label":"pr-12","card":"'.Uuid::v7().'"}');

        self::assertResponseStatusCodeSame(201);
        $body = $this->json();
        self::assertIsString($body['token']);
        self::assertIsString($body['expiresAt']);
        self::assertEqualsWithDelta(time() + 14 * 86400, new \DateTimeImmutable($body['expiresAt'])->getTimestamp(), 60);
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM oauth_preview_tokens WHERE label = 'pr-12'"));

        $this->call(Request::METHOD_GET, '/api/site-review/review', $body['token']);
        self::assertResponseIsSuccessful();
    }

    public function test_a_request_with_a_preview_token_records_its_use_and_a_popup_token_leaves_no_row(): void
    {
        $id = $this->mint('pr-12');
        $preview = AgentCredential::tokenFor(static::getContainer(), $this->owner, 'site-review', $this->project);
        self::assertNull($this->connection->fetchOne('SELECT last_used_at FROM oauth_preview_tokens WHERE access_token_id = :id', ['id' => $id]));

        $previewJwt = $this->mintJwt();
        $this->call(Request::METHOD_GET, '/api/site-review/review', $previewJwt);
        self::assertResponseIsSuccessful();
        self::assertNotNull($this->connection->fetchOne('SELECT last_used_at FROM oauth_preview_tokens WHERE access_token_id = :id', ['id' => self::tokenIdOf($previewJwt)]));

        $this->call(Request::METHOD_GET, '/api/site-review/review', $preview);
        self::assertResponseIsSuccessful();
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM oauth_preview_tokens'));
    }

    public function test_a_mint_finds_the_project_by_slug(): void
    {
        $agent = AgentCredential::agentToken(static::getContainer(), $this->owner);

        $this->call(Request::METHOD_POST, $this->mintPath($this->project->slug ?? ''), $agent, '{"label":"pr-12"}');

        self::assertResponseStatusCodeSame(201);
    }

    public function test_a_second_mint_under_the_label_ends_the_first_token(): void
    {
        $agent = AgentCredential::agentToken(static::getContainer(), $this->owner);
        $path = $this->mintPath((string) $this->project->id);

        $this->call(Request::METHOD_POST, $path, $agent, '{"label":"pr-12"}');
        $first = $this->json()['token'];
        $this->call(Request::METHOD_POST, $path, $agent, '{"label":"pr-12"}');
        $second = $this->json()['token'];

        $this->call(Request::METHOD_GET, '/api/site-review/review', $first);
        self::assertResponseStatusCodeSame(401);
        $this->call(Request::METHOD_GET, '/api/site-review/review', $second);
        self::assertResponseIsSuccessful();
    }

    public function test_another_persons_project_is_not_found_and_mints_nothing(): void
    {
        $stranger = $this->scenario->createUser('preview-api-stranger@example.com');
        $agent = AgentCredential::agentToken(static::getContainer(), $stranger);

        $this->call(Request::METHOD_POST, $this->mintPath((string) $this->project->id), $agent, '{"label":"pr-12"}');

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['error' => 'project_not_found'], $this->json());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM oauth_preview_tokens'));
    }

    /** @return iterable<string, array{string}> */
    public static function badBodies(): iterable
    {
        yield 'no label' => ['{}'];
        yield 'blank label' => ['{"label":"   "}'];
        yield 'long label' => ['{"label":"'.str_repeat('a', 101).'"}'];
        yield 'card not a uuid' => ['{"label":"pr-12","card":"nope"}'];
    }

    #[DataProvider('badBodies')]
    public function test_a_bad_body_is_unprocessable_and_mints_nothing(string $body): void
    {
        $agent = AgentCredential::agentToken(static::getContainer(), $this->owner);

        $this->call(Request::METHOD_POST, $this->mintPath((string) $this->project->id), $agent, $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM oauth_preview_tokens'));
    }

    public function test_a_revoke_ends_the_token_of_the_label_and_a_repeat_succeeds(): void
    {
        $agent = AgentCredential::agentToken(static::getContainer(), $this->owner);
        $this->call(Request::METHOD_POST, $this->mintPath((string) $this->project->id), $agent, '{"label":"pr 12"}');
        $token = $this->json()['token'];

        $this->call(Request::METHOD_DELETE, $this->mintPath((string) $this->project->id).'/pr%2012', $agent);
        self::assertResponseIsSuccessful();
        self::assertSame(['revoked' => 1], $this->json());

        $this->call(Request::METHOD_GET, '/api/site-review/review', $token);
        self::assertResponseStatusCodeSame(401);

        $this->call(Request::METHOD_DELETE, $this->mintPath((string) $this->project->id).'/pr%2012', $agent);
        self::assertResponseIsSuccessful();
        self::assertSame(['revoked' => 0], $this->json());
    }

    public function test_a_revoke_on_another_persons_project_is_not_found_and_revokes_nothing(): void
    {
        $id = $this->mint('pr-12');
        $stranger = $this->scenario->createUser('preview-api-revoke-stranger@example.com');
        $agent = AgentCredential::agentToken(static::getContainer(), $stranger);

        $this->call(Request::METHOD_DELETE, $this->mintPath((string) $this->project->id).'/pr-12', $agent);

        self::assertResponseStatusCodeSame(404);
        self::assertFalse($this->isRevoked($id));
    }

    public function test_the_two_routes_share_a_limit_per_credential(): void
    {
        $this->client->disableReboot();
        static::getContainer()->set('limiter.agent_preview_tokens', new RateLimiterFactory(
            ['id' => 'agent_preview_tokens', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'],
            new InMemoryStorage(),
        ));
        $agent = AgentCredential::agentToken(static::getContainer(), $this->owner);
        $path = $this->mintPath((string) $this->project->id);

        $this->call(Request::METHOD_POST, $path, $agent, '{"label":"pr-12"}');
        self::assertResponseStatusCodeSame(201);

        $this->call(Request::METHOD_DELETE, $path.'/pr-12', $agent);
        self::assertResponseStatusCodeSame(429);
    }

    private function mintJwt(): string
    {
        $agent = AgentCredential::agentToken(static::getContainer(), $this->owner);
        $this->call(Request::METHOD_POST, $this->mintPath((string) $this->project->id), $agent, '{"label":"pr-use"}');
        $token = $this->json()['token'];

        return \is_string($token) ? $token : throw new \LogicException('no token in the response.');
    }

    private function mintPath(string $handle): string
    {
        return '/api/projects/'.rawurlencode($handle).'/preview-tokens';
    }

    private function call(string $method, string $path, string $token, ?string $body = null): void
    {
        $this->client->request($method, $path, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: $body);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? $decoded : throw new \LogicException('the response is not a JSON object.');
    }
}
