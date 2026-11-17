<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth\Security;

use App\Module\OAuth\Command\Dev\MintAccessTokenCommand;
use App\Module\OAuth\Command\Dev\MintAccessTokenHandler;
use App\Module\OAuth\Scope\ApiScope;
use App\Security\AuthenticatedCredential;
use App\Tests\Support\OAuthScenario;
use App\Tests\Support\WorkerRunToken;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Uid\Uuid;

/** What the authenticator takes from the run record of a token. */
final class WorkerRunCredentialAuthenticationTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    public function test_a_run_token_carries_the_bridge_the_run_key_and_the_session_of_its_record(): void
    {
        $scenario = new OAuthScenario(static::getContainer());
        $owner = $scenario->createUser('run-authenticator@example.com');
        $project = $scenario->createProject($owner, 'Run authenticator');
        $bridgeId = Uuid::v7();
        $runKey = Uuid::v7();
        $sessionId = Uuid::v7();
        $token = WorkerRunToken::issue(static::getContainer(), $owner, $project, $bridgeId, $runKey, $sessionId)->token;

        $credential = $this->credentialOf($token);

        self::assertSame((string) $bridgeId, (string) $credential->workerBridgeId);
        self::assertSame((string) $runKey, (string) $credential->workerRunKey);
        self::assertSame((string) $sessionId, (string) $credential->workerSessionId);
        self::assertTrue($credential->hasRole(ApiScope::WorkerRun->role()));
        self::assertFalse($credential->hasRole(ApiScope::Public->role()));
        self::assertFalse($credential->hasRole(ApiScope::Agent->role()));
        self::assertSame((string) $project->id, (string) $credential->projectId);
    }

    public function test_two_launches_of_one_session_carry_their_own_run_keys(): void
    {
        $scenario = new OAuthScenario(static::getContainer());
        $owner = $scenario->createUser('run-authenticator-two@example.com');
        $project = $scenario->createProject($owner, 'Run authenticator two');
        $sessionId = Uuid::v7();
        $first = Uuid::v7();
        $second = Uuid::v7();
        $firstToken = WorkerRunToken::issue(static::getContainer(), $owner, $project, runKey: $first, sessionId: $sessionId)->token;
        $secondToken = WorkerRunToken::issue(static::getContainer(), $owner, $project, runKey: $second, sessionId: $sessionId)->token;

        self::assertSame((string) $first, (string) $this->credentialOf($firstToken)->workerRunKey);
        self::assertSame((string) $second, (string) $this->credentialOf($secondToken)->workerRunKey);
    }

    public function test_a_token_with_no_worker_run_scope_carries_no_run(): void
    {
        $scenario = new OAuthScenario(static::getContainer());
        $owner = $scenario->createUser('run-authenticator-plain@example.com');
        $project = $scenario->createProject($owner, 'Run authenticator plain');
        $mint = static::getContainer()->get(MintAccessTokenHandler::class);
        self::assertInstanceOf(MintAccessTokenHandler::class, $mint);

        $credential = $this->credentialOf($mint(new MintAccessTokenCommand($owner, ['mcp'], $project)));

        self::assertNull($credential->workerBridgeId);
        self::assertNull($credential->workerRunKey);
        self::assertNull($credential->workerSessionId);
    }

    private function credentialOf(string $token): AuthenticatedCredential
    {
        $this->client->request(Request::METHOD_POST, '/mcp', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
        ], content: '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"worker","version":"1"}}}');
        self::assertResponseStatusCodeSame(200);

        $storage = static::getContainer()->get(TokenStorageInterface::class);
        self::assertInstanceOf(TokenStorageInterface::class, $storage);
        $credential = AuthenticatedCredential::of($storage->getToken());
        self::assertNotNull($credential, 'the request left no authenticated credential in the token storage');

        return $credential;
    }
}
