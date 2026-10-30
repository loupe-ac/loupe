<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Security\LoginLivenessInterface;
use App\Tests\Support\OAuthScenario;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/** A refresh keeps the login of the token it replaces, and the login stays alive while one token can still work. */
final class RefreshTokenLoginTest extends WebTestCase
{
    private KernelBrowser $browser;
    private OAuthScenario $scenario;
    private User $user;
    private Project $project;
    private Connection $connection;
    private LoginLivenessInterface $liveness;

    protected function setUp(): void
    {
        $this->browser = static::createClient();
        $this->browser->disableReboot();
        $this->scenario = new OAuthScenario(static::getContainer());
        $this->scenario->createClient();
        $this->user = $this->scenario->createUser('riley@example.com');
        $this->project = $this->scenario->createProject($this->user, 'Riley site');
        $this->connection = static::getContainer()->get(Connection::class);
        $this->liveness = static::getContainer()->get(LoginLivenessInterface::class);
    }

    public function test_a_first_issue_mints_a_login_and_a_refresh_keeps_it(): void
    {
        $first = $this->scenario->grantTokens($this->browser, $this->user, $this->project);
        $second = $this->refresh($first['refresh_token']);

        $logins = $this->connection->fetchFirstColumn('SELECT DISTINCT login_id FROM oauth_refresh_token_login');

        self::assertCount(1, $logins);
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM oauth_refresh_token_login'));
        self::assertSame($logins[0], $this->loginOf($second));
    }

    public function test_the_login_of_an_access_token_is_found_through_its_refresh_token(): void
    {
        $first = $this->scenario->grantTokens($this->browser, $this->user, $this->project);

        $login = $this->liveness->loginOfAccessToken($this->accessTokenIdOf($first));

        self::assertInstanceOf(Uuid::class, $login);
        self::assertSame($this->loginOf($first), $login->toRfc4122());
        self::assertNull($this->liveness->loginOfAccessToken('unknown-access-token'));
    }

    public function test_a_login_with_a_live_token_is_not_expired(): void
    {
        $first = $this->scenario->grantTokens($this->browser, $this->user, $this->project);
        $this->refresh($first['refresh_token']);
        $login = Uuid::fromString($this->loginOf($first));

        self::assertSame([], $this->liveness->expired([$login]));
    }

    public function test_a_login_expires_when_its_tokens_are_revoked_or_gone(): void
    {
        $first = $this->scenario->grantTokens($this->browser, $this->user, $this->project);
        $login = Uuid::fromString($this->loginOf($first));
        $other = Uuid::v7();

        $this->connection->executeStatement('UPDATE oauth2_refresh_token SET revoked = true');
        self::assertEquals([$login, $other], $this->liveness->expired([$login, $other]), 'a revoked login and an unknown login are expired');

        $this->connection->executeStatement('UPDATE oauth2_refresh_token SET revoked = false');
        self::assertEquals([$other], $this->liveness->expired([$login, $other]));

        $this->connection->executeStatement("UPDATE oauth2_refresh_token SET expiry = NOW() - INTERVAL '1 minute'");
        self::assertEquals([$login], $this->liveness->expired([$login]), 'an expired token no longer keeps the login');
    }

    public function test_an_empty_list_asks_nothing(): void
    {
        self::assertSame([], $this->liveness->expired([]));
    }

    public function test_the_tag_goes_with_its_token(): void
    {
        $this->scenario->grantTokens($this->browser, $this->user, $this->project);

        $this->connection->executeStatement('DELETE FROM oauth2_refresh_token');

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM oauth_refresh_token_login'));
    }

    /** @return array<string, mixed> */
    private function refresh(string $refreshToken): array
    {
        $tokens = OAuthScenario::postToken($this->browser, [
            'grant_type' => 'refresh_token',
            'client_id' => OAuthScenario::CLIENT_ID,
            'refresh_token' => $refreshToken,
        ]);
        self::assertResponseStatusCodeSame(200);

        return $tokens;
    }

    /** @param array<string, mixed> $tokens */
    private function accessTokenIdOf(array $tokens): string
    {
        $payload = json_decode(base64_decode(strtr(explode('.', (string) $tokens['access_token'])[1], '-_', '+/'), true) ?: '', true, flags: \JSON_THROW_ON_ERROR);

        return (string) $payload['jti'];
    }

    /** @param array<string, mixed> $tokens */
    private function loginOf(array $tokens): string
    {
        return (string) $this->connection->fetchOne(
            'SELECT l.login_id FROM oauth_refresh_token_login l JOIN oauth2_refresh_token r ON r.identifier = l.refresh_token_identifier WHERE r.access_token = :id',
            ['id' => $this->accessTokenIdOf($tokens)],
        );
    }
}
