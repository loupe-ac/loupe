<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth;

use App\Module\Account\Entity\User;
use App\Module\OAuth\Command\PurgeExpiredOAuthTokensCommand;
use App\Module\OAuth\Command\PurgeExpiredOAuthTokensHandler;
use App\Module\OAuth\Command\RevokeConnectedAppCommand;
use App\Module\OAuth\Command\RevokeConnectedAppHandler;
use App\Module\OAuth\Command\RevokePreviewTokensCommand;
use App\Module\OAuth\Command\RevokePreviewTokensHandler;
use App\Module\OAuth\EventListener\DeleteOAuthGrantsOnProjectDeleting;
use App\Module\Project\Event\ProjectDeleting;
use App\Tests\Support\AgentCredential;
use App\Tests\Support\CapturedAccountSignals;
use App\Tests\Support\OAuthScenario;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/** Each writer of the connected apps page tells the apps area of the owner, and a change that never commits tells nothing. */
final class AccountSignalsTest extends KernelTestCase
{
    use PreviewTokenScenario;

    private CapturedAccountSignals $signals;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->signals = CapturedAccountSignals::install(self::getContainer());
        $this->setUpScenario();
        $this->scenario->createClient();
    }

    public function test_an_issued_access_token_signals_the_apps_area_of_its_user(): void
    {
        AgentCredential::agentToken(self::getContainer(), $this->owner);
        $this->signals->endRequest();

        self::assertSame(['apps'], array_values(array_unique($this->apps($this->id($this->owner)))));
    }

    public function test_an_access_token_of_another_user_signals_that_user_only(): void
    {
        $other = $this->scenario->createUser('other-token-user@example.com');

        AgentCredential::agentToken(self::getContainer(), $other);
        $this->signals->endRequest();

        self::assertSame([], $this->apps($this->id($this->owner)));
        self::assertNotSame([], $this->apps($this->id($other)));
    }

    public function test_a_token_that_is_rolled_back_signals_nothing(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        try {
            $em->wrapInTransaction(function (): never {
                AgentCredential::agentToken(self::getContainer(), $this->owner);

                throw new \RuntimeException('the refresh did not answer 200');
            });
        } catch (\RuntimeException) {
        }
        $this->signals->endRequest();

        self::assertSame([], $this->apps($this->id($this->owner)));
    }

    public function test_a_preview_token_mint_and_a_revoke_by_token_signal_the_owner(): void
    {
        $tokenId = $this->mint('signal-label');
        $this->signals->endRequest();
        $afterMint = $this->apps($this->id($this->owner));

        self::getContainer()->get(RevokePreviewTokensHandler::class)(new RevokePreviewTokensCommand(owner: $this->owner, accessTokenId: $tokenId));
        $this->signals->endRequest();

        self::assertNotSame([], $afterMint);
        self::assertSame(['apps'], array_values(array_unique($afterMint)));
        self::assertGreaterThan(\count($afterMint), \count($this->apps($this->id($this->owner))));
    }

    public function test_a_revoke_by_label_signals_the_owner(): void
    {
        $this->mint('revoke-label');
        $this->signals->endRequest();
        $before = \count($this->apps($this->id($this->owner)));

        self::getContainer()->get(RevokePreviewTokensHandler::class)(new RevokePreviewTokensCommand(owner: $this->owner, handle: (string) $this->project->id, label: 'revoke-label'));
        $this->signals->endRequest();

        self::assertSame($before + 1, \count($this->apps($this->id($this->owner))));
    }

    public function test_a_revoke_by_card_signals_the_owner_of_each_live_token_of_the_card(): void
    {
        $cardId = $this->cardOf($this->project);
        $this->mint('card-token', $cardId);
        $this->signals->endRequest();
        $before = \count($this->apps($this->id($this->owner)));

        self::getContainer()->get(RevokePreviewTokensHandler::class)(new RevokePreviewTokensCommand(cardId: $cardId));
        $this->signals->endRequest();

        self::assertSame($before + 1, \count($this->apps($this->id($this->owner))));
    }

    public function test_a_revoke_that_matches_no_token_signals_nothing(): void
    {
        self::getContainer()->get(RevokePreviewTokensHandler::class)(new RevokePreviewTokensCommand(owner: $this->owner, accessTokenId: 'no-such-token'));
        $this->signals->endRequest();

        self::assertSame([], $this->apps($this->id($this->owner)));
    }

    public function test_revoking_a_connected_app_signals_the_apps_area_of_the_user(): void
    {
        self::getContainer()->get(RevokeConnectedAppHandler::class)(new RevokeConnectedAppCommand($this->owner, OAuthScenario::CLIENT_ID));
        $this->signals->endRequest();

        self::assertSame(['apps'], $this->apps($this->id($this->owner)));
    }

    public function test_a_project_delete_signals_the_apps_area_of_its_owner(): void
    {
        self::getContainer()->get(DeleteOAuthGrantsOnProjectDeleting::class)(new ProjectDeleting($this->project));
        $this->signals->endRequest();

        self::assertSame(['apps'], $this->apps($this->id($this->owner)));
    }

    public function test_the_expired_token_purge_signals_each_user_whose_tokens_expired_and_nobody_else(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        $other = $this->scenario->createUser('purge-live-user@example.com');
        $this->insertToken($connection, 'expired-token', '-1 hour', $this->owner);
        $this->insertToken($connection, 'live-token', '+1 hour', $other);

        self::getContainer()->get(PurgeExpiredOAuthTokensHandler::class)(new PurgeExpiredOAuthTokensCommand());
        $this->signals->endRequest();

        self::assertSame(['apps'], $this->apps($this->id($this->owner)));
        self::assertSame([], $this->apps($this->id($other)));
    }

    /** @return list<string> */
    private function apps(Uuid $userId): array
    {
        return $this->signals->areasOf($userId, 'apps');
    }

    private function insertToken(Connection $connection, string $id, string $expiry, User $user): void
    {
        $connection->insert('oauth2_access_token', [
            'identifier' => $id,
            'expiry' => new \DateTimeImmutable($expiry)->format('Y-m-d H:i:s'),
            'user_identifier' => (string) $user->id,
            'scopes' => 'agent',
            'revoked' => false,
            'client' => OAuthScenario::CLIENT_ID,
        ], ['revoked' => 'boolean']);
    }

    private function id(User $user): Uuid
    {
        return $user->id ?? throw new \LogicException('A flushed user has an id.');
    }
}
