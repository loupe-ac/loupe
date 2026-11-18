<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller;

use App\Tests\Module\OAuth\PreviewTokenScenario;
use App\Tests\Support\AgentCredential;
use App\Tests\Support\OAuthScenario;
use App\Tests\Support\WorkerRunToken;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Uid\Uuid;

final class IssueWorkerRunCredentialApiTest extends WebTestCase
{
    use PreviewTokenScenario;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->setUpScenario('run-credential-owner@example.com');
    }

    public function test_a_request_with_no_token_is_unauthorized(): void
    {
        $this->client->request(Request::METHOD_POST, $this->path((string) Uuid::v7()), server: ['CONTENT_TYPE' => 'application/json'], content: $this->body());

        self::assertResponseStatusCodeSame(401);
    }

    public function test_a_token_with_no_agent_scope_is_refused(): void
    {
        foreach (['site-review', 'mcp', 'public'] as $scope) {
            $token = AgentCredential::tokenFor(static::getContainer(), $this->owner, $scope, 'public' === $scope ? null : $this->project);

            $this->call($this->path((string) Uuid::v7()), $token, $this->body());

            self::assertResponseStatusCodeSame(403, $scope);
        }
        self::assertSame(0, $this->credentialCount());
    }

    public function test_a_run_token_cannot_mint_another_run_token(): void
    {
        $run = WorkerRunToken::issue(static::getContainer(), $this->owner, $this->project);
        $before = $this->credentialCount();

        $this->call($this->path((string) Uuid::v7()), $run->token, $this->body());

        self::assertResponseStatusCodeSame(403);
        self::assertSame($before, $this->credentialCount());
    }

    public function test_a_mint_records_the_launch_and_returns_a_token_for_one_day(): void
    {
        $agent = AgentCredential::agentToken(static::getContainer(), $this->owner);
        $runKey = (string) Uuid::v7();
        $bridgeId = (string) Uuid::v7();
        $sessionId = (string) Uuid::v7();

        $this->call($this->path($runKey), $agent, $this->body($bridgeId, $sessionId));

        self::assertResponseStatusCodeSame(201);
        $body = $this->json();
        self::assertIsString($body['token']);
        self::assertEqualsWithDelta(time() + 86400, new \DateTimeImmutable((string) $body['expiresAt'])->getTimestamp(), 60);

        $row = $this->connection->fetchAssociative('SELECT * FROM oauth_worker_run_credentials');
        self::assertIsArray($row);
        self::assertSame(self::tokenIdOf($body['token']), $row['access_token_id']);
        self::assertSame($runKey, $row['run_key']);
        self::assertSame($bridgeId, $row['bridge_id']);
        self::assertSame($sessionId, $row['session_id']);
        self::assertSame((string) $this->project->id, $row['project_id']);
        self::assertSame((string) $this->owner->id, $row['user_id']);
        self::assertNull($row['revoked_at']);
    }

    public function test_the_token_holds_the_run_scopes_and_one_project_and_no_agent_or_public_scope(): void
    {
        $agent = AgentCredential::agentToken(static::getContainer(), $this->owner);

        $this->call($this->path((string) Uuid::v7()), $agent, $this->body());

        self::assertResponseStatusCodeSame(201);
        $claims = OAuthScenario::claimsOf((string) $this->json()['token']);
        $scopes = $claims['scopes'] ?? null;
        self::assertIsArray($scopes);
        self::assertEqualsCanonicalizing(['mcp', 'worker-run', 'project:'.$this->project->id], $scopes);
    }

    public function test_the_token_has_no_refresh_token(): void
    {
        $agent = AgentCredential::agentToken(static::getContainer(), $this->owner);
        $refreshBefore = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM oauth2_refresh_token');

        $this->call($this->path((string) Uuid::v7()), $agent, $this->body());

        self::assertResponseStatusCodeSame(201);
        self::assertArrayNotHasKey('refresh_token', $this->json());
        self::assertSame($refreshBefore, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM oauth2_refresh_token'));
        self::assertSame(1, $this->credentialCount());
    }

    public function test_the_access_token_row_expires_in_a_day_and_the_record_has_no_foreign_key_to_it(): void
    {
        $agent = AgentCredential::agentToken(static::getContainer(), $this->owner);
        $this->call($this->path((string) Uuid::v7()), $agent, $this->body());
        $id = self::tokenIdOf((string) $this->json()['token']);

        $this->connection->executeStatement('DELETE FROM oauth2_access_token WHERE identifier = :id', ['id' => $id]);

        self::assertSame(1, $this->credentialCount());
    }

    public function test_a_mint_finds_the_project_by_slug(): void
    {
        $agent = AgentCredential::agentToken(static::getContainer(), $this->owner);

        $this->call($this->path((string) Uuid::v7(), $this->project->slug ?? ''), $agent, $this->body());

        self::assertResponseStatusCodeSame(201);
    }

    public function test_another_persons_project_is_not_found_and_mints_nothing(): void
    {
        $stranger = $this->scenario->createUser('run-credential-stranger@example.com');
        $agent = AgentCredential::agentToken(static::getContainer(), $stranger);

        $this->call($this->path((string) Uuid::v7()), $agent, $this->body());

        self::assertResponseStatusCodeSame(404);
        self::assertSame('project_not_found', $this->json()['code']);
        self::assertSame(0, $this->credentialCount());
    }

    public function test_a_run_key_that_is_no_uuid_is_refused(): void
    {
        $agent = AgentCredential::agentToken(static::getContainer(), $this->owner);

        $this->call($this->path('not-a-uuid'), $agent, $this->body());

        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_run_id', $this->json()['code']);
        self::assertSame(0, $this->credentialCount());
    }

    public function test_a_body_without_a_bridge_or_a_session_is_refused(): void
    {
        $agent = AgentCredential::agentToken(static::getContainer(), $this->owner);

        foreach (['{}', '{"bridgeId":"'.Uuid::v7().'"}', '{"sessionId":"'.Uuid::v7().'"}', '{"bridgeId":"x","sessionId":"'.Uuid::v7().'"}'] as $content) {
            $this->call($this->path((string) Uuid::v7()), $agent, $content);

            self::assertResponseStatusCodeSame(422, $content);
        }
        self::assertSame(0, $this->credentialCount());
    }

    public function test_a_launch_that_asks_too_often_is_throttled_and_another_launch_is_not(): void
    {
        $this->client->disableReboot();
        static::getContainer()->set('limiter.agent_worker_run_credentials', new RateLimiterFactory(
            ['id' => 'agent_worker_run_credentials', 'policy' => 'sliding_window', 'limit' => 2, 'interval' => '1 minute'],
            new InMemoryStorage(),
        ));
        $agent = AgentCredential::agentToken(static::getContainer(), $this->owner);
        $runKey = (string) Uuid::v7();
        $body = $this->body();

        $this->call($this->path($runKey), $agent, $body);
        self::assertResponseStatusCodeSame(201);
        $this->call($this->path($runKey), $agent, $body);
        self::assertResponseStatusCodeSame(201);
        $this->call($this->path($runKey), $agent, $body);
        self::assertResponseStatusCodeSame(429);
        self::assertSame(2, $this->credentialCount());

        $this->call($this->path((string) Uuid::v7()), $agent, $this->body());
        self::assertResponseStatusCodeSame(201);
    }

    private function path(string $runKey, ?string $handle = null): string
    {
        return '/api/v1/bridge/projects/'.($handle ?? $this->project->id).'/worker-runs/'.$runKey.'/credential';
    }

    private function body(?string $bridgeId = null, ?string $sessionId = null): string
    {
        return json_encode(['bridgeId' => $bridgeId ?? (string) Uuid::v7(), 'sessionId' => $sessionId ?? (string) Uuid::v7()], \JSON_THROW_ON_ERROR);
    }

    private function call(string $path, string $token, string $content): void
    {
        $this->client->request(Request::METHOD_POST, $path, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: $content);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function credentialCount(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM oauth_worker_run_credentials');
    }
}
