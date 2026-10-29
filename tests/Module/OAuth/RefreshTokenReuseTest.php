<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth;

use App\Module\Account\Entity\User;
use App\Module\OAuth\Install\OAuthInstallFlags;
use App\Module\Project\Entity\Project;
use App\Tests\Support\OAuthScenario;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/** A client whose refresh answer was lost redeems the old token again, until it uses the new one. */
final class RefreshTokenReuseTest extends WebTestCase
{
    private const string INIT = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"t","version":"1"}}}';

    private KernelBrowser $browser;
    private OAuthScenario $scenario;
    private User $user;
    private Project $project;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->browser = static::createClient();
        $this->browser->disableReboot();
        $this->scenario = new OAuthScenario(static::getContainer());
        $this->scenario->createClient();
        $this->user = $this->scenario->createUser('riley@example.com');
        $this->project = $this->scenario->createProject($this->user, 'Riley site');
        $this->connection = static::getContainer()->get(Connection::class);
    }

    public function test_the_old_token_redeems_again_and_the_unused_successor_pair_dies(): void
    {
        $first = $this->scenario->grantTokens($this->browser, $this->user, $this->project);
        $second = $this->refresh($first['refresh_token']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(200, $this->callMcp($second['access_token']));

        $third = $this->refresh($first['refresh_token']);

        self::assertResponseStatusCodeSame(200);
        self::assertNotSame($second['refresh_token'], $third['refresh_token']);
        self::assertSame(401, $this->callMcp($second['access_token']), 'the unused successor access token is revoked');
        self::assertSame(200, $this->callMcp($third['access_token']));
        $this->refresh($second['refresh_token']);
        self::assertResponseStatusCodeSame(400);
    }

    public function test_the_old_token_is_dead_once_the_successor_was_used(): void
    {
        $first = $this->scenario->grantTokens($this->browser, $this->user, $this->project);
        $second = $this->refresh($first['refresh_token']);
        $this->refresh($second['refresh_token']);
        self::assertResponseStatusCodeSame(200);

        $replay = $this->refresh($first['refresh_token']);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_grant', $replay['error']);
    }

    public function test_a_retry_keeps_the_time_of_the_first_rotation(): void
    {
        $first = $this->scenario->grantTokens($this->browser, $this->user, $this->project);
        $this->refresh($first['refresh_token']);
        $this->connection->executeStatement("UPDATE oauth_refresh_token_rotation SET rotated_at = NOW() - INTERVAL '3 days'");
        $before = $this->rotatedAt();

        $this->refresh($first['refresh_token']);
        self::assertResponseStatusCodeSame(200);

        self::assertSame($before, $this->rotatedAt());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM oauth_refresh_token_rotation'));
    }

    public function test_the_old_token_is_refused_after_the_limit(): void
    {
        $first = $this->scenario->grantTokens($this->browser, $this->user, $this->project);
        $this->refresh($first['refresh_token']);
        $this->connection->executeStatement("UPDATE oauth_refresh_token_rotation SET rotated_at = NOW() - INTERVAL '8 days'");

        $this->refresh($first['refresh_token']);

        self::assertResponseStatusCodeSame(400);
    }

    public function test_the_old_token_is_refused_when_the_flag_is_off(): void
    {
        $this->connection->executeStatement(
            "INSERT INTO feature_flag (name, type, value, tags, options) VALUES (?, 'bool', 'false', '[]', NULL)
             ON CONFLICT (name) DO UPDATE SET value = 'false'",
            [OAuthInstallFlags::FLAG_REFRESH_REUSE],
        );
        $first = $this->scenario->grantTokens($this->browser, $this->user, $this->project);
        $this->refresh($first['refresh_token']);

        $this->refresh($first['refresh_token']);

        self::assertResponseStatusCodeSame(400);
    }

    public function test_an_owner_revoke_kills_the_old_token_too(): void
    {
        $first = $this->scenario->grantTokens($this->browser, $this->user, $this->project);
        $this->refresh($first['refresh_token']);
        $this->connection->executeStatement('UPDATE oauth2_refresh_token SET revoked = true');

        $this->refresh($first['refresh_token']);

        self::assertResponseStatusCodeSame(400);
    }

    public function test_a_refused_refresh_leaves_the_rotation_as_it_was(): void
    {
        $first = $this->scenario->grantTokens($this->browser, $this->user, $this->project);
        $this->refresh($first['refresh_token']);
        $this->connection->executeStatement("UPDATE oauth_refresh_token_rotation SET rotated_at = NOW() - INTERVAL '8 days'");
        $this->refresh($first['refresh_token']);

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM oauth_refresh_token_rotation'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM oauth2_refresh_token WHERE revoked = false'));
    }

    public function test_deleting_a_refresh_token_removes_its_rotation_row(): void
    {
        $first = $this->scenario->grantTokens($this->browser, $this->user, $this->project);
        $this->refresh($first['refresh_token']);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM oauth_refresh_token_rotation'));

        $this->connection->executeStatement('DELETE FROM oauth2_refresh_token');

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM oauth_refresh_token_rotation'));
    }

    /** @return array<string, mixed> */
    private function refresh(string $refreshToken): array
    {
        return OAuthScenario::postToken($this->browser, [
            'grant_type' => 'refresh_token',
            'client_id' => OAuthScenario::CLIENT_ID,
            'refresh_token' => $refreshToken,
        ]);
    }

    private function rotatedAt(): string
    {
        return (string) $this->connection->fetchOne('SELECT rotated_at FROM oauth_refresh_token_rotation');
    }

    private function callMcp(string $bearer): int
    {
        $this->browser->request(Request::METHOD_POST, '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$bearer,
        ], content: self::INIT);

        return $this->browser->getResponse()->getStatusCode();
    }
}
