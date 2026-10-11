<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth\Command;

use App\Exception\DomainErrors;
use App\Module\OAuth\Command\RevokePreviewTokensCommand;
use App\Module\OAuth\Command\RevokePreviewTokensHandler;
use App\Tests\Module\OAuth\PreviewTokenScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class RevokePreviewTokensHandlerTest extends KernelTestCase
{
    use PreviewTokenScenario;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->setUpScenario();
    }

    public function test_a_revoke_by_label_revokes_that_label_alone(): void
    {
        $target = $this->mint('pr-12');
        $other = $this->mint('pr-13');

        $count = $this->revoke(new RevokePreviewTokensCommand(owner: $this->owner, handle: (string) $this->project->id, label: 'pr-12'));

        self::assertSame(1, $count);
        self::assertTrue($this->isRevoked($target));
        self::assertFalse($this->isRevoked($other));
    }

    public function test_a_revoke_by_label_finds_the_project_by_slug(): void
    {
        $target = $this->mint('pr-12');

        $this->revoke(new RevokePreviewTokensCommand(owner: $this->owner, handle: $this->project->slug, label: 'pr-12'));

        self::assertTrue($this->isRevoked($target));
    }

    public function test_a_revoke_by_token_id_revokes_that_token_alone(): void
    {
        $target = $this->mint('pr-12');
        $other = $this->mint('pr-13');

        $count = $this->revoke(new RevokePreviewTokensCommand(accessTokenId: $target));

        self::assertSame(1, $count);
        self::assertTrue($this->isRevoked($target));
        self::assertFalse($this->isRevoked($other));
    }

    public function test_a_revoke_by_card_revokes_every_token_of_the_card_of_any_person(): void
    {
        $cardId = Uuid::v7();
        $mine = $this->mint('pr-12', $cardId);
        $otherPerson = $this->scenario->createUser('preview-card-other@example.com');
        $otherProject = $this->scenario->createProject($otherPerson, 'Their site');
        $theirs = $this->mint('pr-12', $cardId, $otherPerson, $otherProject);
        $otherCard = $this->mint('pr-14', Uuid::v7());
        $noCard = $this->mint('pr-15');

        $count = $this->revoke(new RevokePreviewTokensCommand(cardId: $cardId));

        self::assertSame(2, $count);
        self::assertTrue($this->isRevoked($mine));
        self::assertTrue($this->isRevoked($theirs));
        self::assertFalse($this->isRevoked($otherCard));
        self::assertFalse($this->isRevoked($noCard));
    }

    public function test_a_refresh_token_of_a_revoked_token_is_revoked_too(): void
    {
        $target = $this->mint('pr-12');
        $this->connection->insert('oauth2_refresh_token', [
            'identifier' => 'preview-refresh',
            'expiry' => new \DateTimeImmutable('+1 month')->format('Y-m-d H:i:s'),
            'revoked' => false,
            'access_token' => $target,
        ], ['revoked' => 'boolean']);

        $this->revoke(new RevokePreviewTokensCommand(accessTokenId: $target));

        self::assertTrue((bool) $this->connection->fetchOne("SELECT revoked FROM oauth2_refresh_token WHERE identifier = 'preview-refresh'"));
    }

    public function test_a_revoke_with_no_match_succeeds_and_revokes_nothing(): void
    {
        $live = $this->mint('pr-12');

        self::assertSame(0, $this->revoke(new RevokePreviewTokensCommand(owner: $this->owner, handle: (string) $this->project->id, label: 'no-such-label')));
        self::assertSame(0, $this->revoke(new RevokePreviewTokensCommand(accessTokenId: 'no-such-token')));
        self::assertSame(0, $this->revoke(new RevokePreviewTokensCommand(cardId: Uuid::v7())));
        self::assertFalse($this->isRevoked($live));
    }

    public function test_a_second_revoke_of_the_same_label_counts_nothing(): void
    {
        $this->mint('pr-12');
        $command = new RevokePreviewTokensCommand(owner: $this->owner, handle: (string) $this->project->id, label: 'pr-12');

        self::assertSame(1, $this->revoke($command));
        self::assertSame(0, $this->revoke($command));
    }

    public function test_a_revoke_by_label_refuses_a_project_the_person_does_not_own(): void
    {
        $live = $this->mint('pr-12');
        $stranger = $this->scenario->createUser('preview-revoke-stranger@example.com');

        try {
            $this->revoke(new RevokePreviewTokensCommand(owner: $stranger, handle: (string) $this->project->id, label: 'pr-12'));
            self::fail('A stranger revoked a token.');
        } catch (DomainErrors $e) {
            self::assertSame(['handle' => 'oauth.preview_token.error.project_not_found'], $e->errors);
        }

        self::assertFalse($this->isRevoked($live));
    }

    private function revoke(RevokePreviewTokensCommand $command): int
    {
        $handler = static::getContainer()->get(RevokePreviewTokensHandler::class);
        self::assertInstanceOf(RevokePreviewTokensHandler::class, $handler);

        return $handler($command);
    }
}
