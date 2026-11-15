<?php

declare(strict_types=1);

namespace App\Module\OAuth\Service\Dev;

use App\DevSeed\DevSeederInterface;
use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Account\Service\Dev\DevAccountSeeder;
use App\Module\OAuth\Scope\GrantedScope;
use App\Module\Project\Service\Dev\DevProjectSeeder;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * The state of the connected apps page with an app row: the keys account
 * holds one live grant of one client. The empty twin is the keys-empty
 * account, which holds none.
 */
#[When('dev')]
#[When('test')]
final readonly class DevConnectedAppSeeder implements DevSeederInterface
{
    public const string KEY = 'connected-apps';

    public const string CLIENT_ID = 'dev-seed-connected-app';

    public function __construct(
        private Connection $connection,
    ) {
    }

    #[\Override]
    public function key(): string
    {
        return self::KEY;
    }

    #[\Override]
    public function dependsOn(): array
    {
        return [DevAccountSeeder::KEY, DevProjectSeeder::KEY];
    }

    #[\Override]
    public function seed(SeedContext $context): void
    {
        $this->connection->executeStatement(
            'INSERT INTO oauth2_client (identifier, name, secret, redirect_uris, grants, scopes, active, allow_plain_text_pkce)
             VALUES (:id, :name, NULL, :redirects, :grants, :scopes, true, false)
             ON CONFLICT (identifier) DO NOTHING',
            [
                'id' => self::CLIENT_ID,
                'name' => 'Dev Seed Assistant',
                'redirects' => 'https://client.example/callback',
                'grants' => 'authorization_code refresh_token',
                'scopes' => 'mcp',
            ],
        );
        $this->connection->executeStatement(
            'INSERT INTO oauth2_access_token (identifier, expiry, user_identifier, scopes, revoked, client)
             VALUES (:token, :expiry, :user, :scopes, false, :client)
             ON CONFLICT (identifier) DO UPDATE SET expiry = EXCLUDED.expiry, revoked = false',
            [
                'token' => substr(hash('sha256', 'dev-seed/connected-app'), 0, 80),
                'expiry' => $context->now->modify('+30 days')->format('Y-m-d H:i:s'),
                'user' => DevSeedId::of(DevAccountSeeder::KEYS_USER)->toRfc4122(),
                'scopes' => 'mcp '.GrantedScope::projectScope(DevProjectSeeder::keysProjectId()),
                'client' => self::CLIENT_ID,
            ],
        );
    }
}
