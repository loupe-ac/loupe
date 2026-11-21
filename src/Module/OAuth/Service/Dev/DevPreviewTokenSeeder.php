<?php

declare(strict_types=1);

namespace App\Module\OAuth\Service\Dev;

use App\DevSeed\DevSeederInterface;
use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\OAuth\Scope\GrantedScope;
use App\Module\OAuth\Widget\WidgetClient;
use App\Module\Project\Service\Dev\DevProjectSeeder;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Writes one live preview token of the dev account for the checkout card of the dev project, so that card page shows
 * the preview sign-in panel with its revoke button. The card has a fixed id, and the seeder writes it only as the
 * dev seed of the board does.
 */
#[When('dev')]
#[When('test')]
final readonly class DevPreviewTokenSeeder implements DevSeederInterface
{
    public const string KEY = 'preview-token';

    public function __construct(
        private Connection $connection,
    ) {
    }

    public static function cardId(): string
    {
        return DevSeedId::of('board/card/checkout')->toRfc4122();
    }

    #[\Override]
    public function key(): string
    {
        return self::KEY;
    }

    #[\Override]
    public function dependsOn(): array
    {
        return [DevProjectSeeder::KEY, 'board'];
    }

    #[\Override]
    public function seed(SeedContext $context): void
    {
        $user = (string) $context->devUser()->id;
        $project = DevProjectSeeder::devProjectId()->toRfc4122();
        $token = substr(hash('sha256', 'dev-seed/preview-token'), 0, 80);

        $this->connection->executeStatement(
            'INSERT INTO oauth2_access_token (identifier, expiry, user_identifier, scopes, revoked, client)
             VALUES (:token, :expiry, :user, :scopes, false, :client)
             ON CONFLICT (identifier) DO UPDATE SET expiry = EXCLUDED.expiry, revoked = false',
            [
                'token' => $token,
                'expiry' => $context->now->modify('+7 days')->format('Y-m-d H:i:s'),
                'user' => $user,
                'scopes' => WidgetClient::SCOPE.' '.GrantedScope::projectScope(DevProjectSeeder::devProjectId()),
                'client' => WidgetClient::ID,
            ],
        );
        $this->connection->executeStatement(
            'INSERT INTO oauth_preview_tokens (access_token_id, user_id, project_id, label, card_id, created_at)
             VALUES (:token, :user, :project, :label, :card, :created)
             ON CONFLICT (access_token_id) DO NOTHING',
            [
                'token' => $token,
                'user' => $user,
                'project' => $project,
                'label' => 'Dev preview',
                'card' => self::cardId(),
                'created' => $context->at(3_600)->format('Y-m-d H:i:s'),
            ],
        );
    }
}
