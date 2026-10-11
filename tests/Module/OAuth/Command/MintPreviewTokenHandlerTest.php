<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth\Command;

use App\Exception\DomainErrors;
use App\Module\OAuth\Command\MintPreviewTokenCommand;
use App\Module\OAuth\Command\MintPreviewTokenHandler;
use App\Module\OAuth\Widget\WidgetClient;
use App\Tests\Module\OAuth\PreviewTokenScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class MintPreviewTokenHandlerTest extends KernelTestCase
{
    use PreviewTokenScenario;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->setUpScenario();
    }

    public function test_the_token_is_a_widget_sign_in_of_the_project_that_lasts_14_days(): void
    {
        $cardId = $this->cardOf($this->project);
        $before = new \DateTimeImmutable();

        $id = $this->mint('pr-12', $cardId);

        $row = $this->connection->fetchAssociative('SELECT client, scopes, user_identifier, expiry, revoked FROM oauth2_access_token WHERE identifier = :id', ['id' => $id]);
        self::assertIsArray($row);
        self::assertSame(WidgetClient::ID, trim((string) $row['client']));
        self::assertSame('site-review project:'.$this->project->id, $row['scopes']);
        self::assertSame((string) $this->owner->id, $row['user_identifier']);
        self::assertFalse((bool) $row['revoked']);

        $expiry = new \DateTimeImmutable((string) $row['expiry']);
        $seconds = $expiry->getTimestamp() - $before->getTimestamp();
        self::assertEqualsWithDelta(14 * 86400, $seconds, 60);

        $preview = $this->connection->fetchAssociative('SELECT user_id, project_id, card_id, label, last_used_at FROM oauth_preview_tokens WHERE access_token_id = :id', ['id' => $id]);
        self::assertIsArray($preview);
        self::assertSame((string) $this->owner->id, $preview['user_id']);
        self::assertSame((string) $this->project->id, $preview['project_id']);
        self::assertSame((string) $cardId, $preview['card_id']);
        self::assertSame('pr-12', $preview['label']);
        self::assertNull($preview['last_used_at']);
    }

    public function test_no_refresh_token_is_issued(): void
    {
        $this->mint('pr-12');

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM oauth2_refresh_token'));
    }

    public function test_a_second_mint_under_the_same_label_revokes_the_first_and_leaves_other_tokens(): void
    {
        $first = $this->mint('pr-12');
        $otherLabel = $this->mint('pr-13');
        $otherProject = $this->scenario->createProject($this->owner, 'Second site');
        $sameLabelElsewhere = $this->mint('pr-12', project: $otherProject);
        $otherPerson = $this->scenario->createUser('preview-other@example.com');
        $otherPersonProject = $this->scenario->createProject($otherPerson, 'Their site');
        $sameLabelOtherPerson = $this->mint('pr-12', owner: $otherPerson, project: $otherPersonProject);

        $second = $this->mint('pr-12');

        self::assertNotSame($first, $second);
        self::assertTrue($this->isRevoked($first));
        self::assertFalse($this->isRevoked($second));
        self::assertFalse($this->isRevoked($otherLabel));
        self::assertFalse($this->isRevoked($sameLabelElsewhere));
        self::assertFalse($this->isRevoked($sameLabelOtherPerson));
    }

    public function test_a_person_who_does_not_own_the_project_is_refused_and_nothing_is_issued(): void
    {
        $stranger = $this->scenario->createUser('preview-stranger@example.com');
        $handler = static::getContainer()->get(MintPreviewTokenHandler::class);
        self::assertInstanceOf(MintPreviewTokenHandler::class, $handler);

        try {
            $handler(new MintPreviewTokenCommand($stranger, (string) $this->project->id, 'pr-12'));
            self::fail('A stranger minted a token.');
        } catch (DomainErrors $e) {
            self::assertSame(['handle' => 'oauth.preview_token.error.project_not_found'], $e->errors);
        }

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM oauth_preview_tokens'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM oauth2_access_token'));
    }

    public function test_a_card_of_another_project_or_no_card_at_all_is_refused_and_nothing_is_issued(): void
    {
        $otherProject = $this->scenario->createProject($this->owner, 'Second site');
        $handler = static::getContainer()->get(MintPreviewTokenHandler::class);
        self::assertInstanceOf(MintPreviewTokenHandler::class, $handler);

        foreach ([$this->cardOf($otherProject), Uuid::v7()] as $cardId) {
            try {
                $handler(new MintPreviewTokenCommand($this->owner, (string) $this->project->id, 'pr-12', $cardId));
                self::fail('A token was minted for a card outside the project.');
            } catch (DomainErrors $e) {
                self::assertSame(['card' => 'oauth.preview_token.error.card_not_found'], $e->errors);
            }
        }

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM oauth_preview_tokens'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM oauth2_access_token'));
    }

    public function test_a_card_in_a_terminal_column_is_refused_and_nothing_is_issued(): void
    {
        $handler = static::getContainer()->get(MintPreviewTokenHandler::class);
        self::assertInstanceOf(MintPreviewTokenHandler::class, $handler);

        try {
            $handler(new MintPreviewTokenCommand($this->owner, (string) $this->project->id, 'pr-12', $this->cardOf($this->project, finished: true)));
            self::fail('A token was minted for a finished card.');
        } catch (DomainErrors $e) {
            self::assertSame(['card' => 'oauth.preview_token.error.card_finished'], $e->errors);
        }

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM oauth_preview_tokens'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM oauth2_access_token'));
    }

    public function test_a_blank_or_long_label_is_refused(): void
    {
        $handler = static::getContainer()->get(MintPreviewTokenHandler::class);
        self::assertInstanceOf(MintPreviewTokenHandler::class, $handler);

        foreach (['  ', str_repeat('a', 101)] as $label) {
            try {
                $handler(new MintPreviewTokenCommand($this->owner, (string) $this->project->id, $label));
                self::fail('A bad label was accepted.');
            } catch (DomainErrors $e) {
                self::assertSame(['label' => 'oauth.preview_token.error.label_invalid'], $e->errors);
            }
        }
    }
}
