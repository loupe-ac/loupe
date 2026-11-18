<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\OAuthScenario;
use App\Tests\Support\WorkerRunToken;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/** A run state that closes a launch revokes the run tokens of that launch alone. */
final class WorkerRunCredentialRevocationTest extends WebTestCase
{
    use BridgeScenario;

    private KernelBrowser $client;
    private User $owner;
    private Project $project;
    private Connection $connection;
    private Uuid $bridgeId;
    private Uuid $sessionId;
    private string $cardId;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = $this->em();
        $this->owner = $this->user($em, 'run-revocation@example.com');
        $this->project = $this->project($em, $this->owner, 'Run revocation');
        $connection = static::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->bridgeId = Uuid::v7();
        $this->sessionId = Uuid::v7();
        $this->cardId = (string) $this->seedCard($em, $this->project)->id;
    }

    /** @return iterable<string, array{string}> */
    public static function closingStates(): iterable
    {
        yield 'succeeded' => ['succeeded'];
        yield 'failed' => ['failed'];
        yield 'blocked' => ['blocked'];
        yield 'stopped' => ['stopped'];
    }

    #[DataProvider('closingStates')]
    public function test_a_closing_report_revokes_the_token_of_the_launch(string $state): void
    {
        $runKey = Uuid::v7();
        $token = $this->issue($runKey);
        self::assertFalse($this->isRevoked($token));

        $this->report($runKey, $state);

        self::assertResponseIsSuccessful();
        self::assertTrue($this->isRevoked($token));
        self::assertNotNull($this->revokedAt($token));
    }

    /** @return iterable<string, array{string}> */
    public static function openStates(): iterable
    {
        yield 'queued' => ['queued'];
        yield 'preparing' => ['preparing'];
        yield 'running' => ['running'];
        yield 'stopping' => ['stopping'];
    }

    #[DataProvider('openStates')]
    public function test_a_report_that_leaves_the_run_open_revokes_nothing(string $state): void
    {
        $runKey = Uuid::v7();
        $token = $this->issue($runKey);

        $this->report($runKey, $state);

        self::assertResponseIsSuccessful();
        self::assertFalse($this->isRevoked($token));
        self::assertNull($this->revokedAt($token));
    }

    public function test_the_close_of_one_launch_leaves_every_other_launch_of_the_session_alone(): void
    {
        $old = Uuid::v7();
        $resumed = Uuid::v7();
        $oldToken = $this->issue($old);
        $resumedToken = $this->issue($resumed);
        $this->report($resumed, 'running');

        $this->report($old, 'succeeded');

        self::assertResponseIsSuccessful();
        self::assertTrue($this->isRevoked($oldToken));
        self::assertFalse($this->isRevoked($resumedToken));
        self::assertNull($this->revokedAt($resumedToken));
    }

    public function test_a_close_of_a_run_on_another_bridge_leaves_the_token_alone(): void
    {
        $runKey = Uuid::v7();
        $token = $this->issue($runKey);

        $this->report($runKey, 'succeeded', Uuid::v7());

        self::assertResponseIsSuccessful();
        self::assertFalse($this->isRevoked($token));
    }

    public function test_a_repeated_close_changes_nothing_more(): void
    {
        $runKey = Uuid::v7();
        $token = $this->issue($runKey);
        $this->report($runKey, 'succeeded');
        $firstRevokedAt = $this->revokedAt($token);

        $this->report($runKey, 'succeeded');

        self::assertResponseIsSuccessful();
        self::assertTrue($this->isRevoked($token));
        self::assertSame($firstRevokedAt, $this->revokedAt($token));
    }

    private function issue(Uuid $runKey): string
    {
        $jwt = WorkerRunToken::issue(static::getContainer(), $this->owner, $this->project, $this->bridgeId, $runKey, $this->sessionId)->token;
        $claims = OAuthScenario::claimsOf($jwt);

        return \is_string($claims['jti'] ?? null) ? $claims['jti'] : throw new \LogicException('the token has no jti claim.');
    }

    private function report(Uuid $runKey, string $state, ?Uuid $bridgeId = null): void
    {
        $outcome = \in_array($state, ['succeeded', 'failed', 'blocked'], true);
        $payload = [
            'bridgeId' => (string) ($bridgeId ?? $this->bridgeId),
            'state' => $state,
            'at' => '2026-09-23T10:00:00+00:00',
            'subjectType' => 'card',
            'subjectId' => $this->cardId,
            'cardNumber' => 1,
            'workKind' => 'plan',
            'sessionId' => (string) $this->sessionId,
        ];
        if ('running' === $state || $outcome || 'stopped' === $state || 'stopping' === $state) {
            $payload['startedAt'] = '2026-09-23T10:00:00+00:00';
        }
        if ($outcome) {
            $payload += [
                'endedAt' => '2026-09-23T10:01:00+00:00',
                'exitCode' => 'failed' === $state ? 1 : 0,
                'hasResult' => true,
                'failureReason' => null,
                'output' => '',
            ];
            if ('blocked' === $state) {
                $payload['resultStatus'] = 'blocked';
            }
        }

        $this->client->request(
            Request::METHOD_PUT,
            '/api/v1/bridge/projects/'.$this->project->id.'/worker-runs/'.$runKey,
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->agentToken($this->client, $this->owner),
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );
    }

    private function isRevoked(string $accessTokenId): bool
    {
        return (bool) $this->connection->fetchOne('SELECT revoked FROM oauth2_access_token WHERE identifier = :id', ['id' => $accessTokenId]);
    }

    private function revokedAt(string $accessTokenId): ?string
    {
        $value = $this->connection->fetchOne('SELECT revoked_at FROM oauth_worker_run_credentials WHERE access_token_id = :id', ['id' => $accessTokenId]);

        return false === $value ? null : (null === $value ? null : (string) $value);
    }
}
