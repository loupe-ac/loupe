<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth\Repository;

use App\Module\OAuth\Command\PreviewTokenRow;
use App\Module\OAuth\Repository\PreviewTokenRepository;
use App\Tests\Module\OAuth\PreviewTokenScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class PreviewTokenRepositoryTest extends KernelTestCase
{
    use PreviewTokenScenario;

    private PreviewTokenRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->setUpScenario();
        $this->repository = static::getContainer()->get(PreviewTokenRepository::class);
    }

    public function test_the_first_use_is_recorded(): void
    {
        $id = $this->mint('pr-12');
        self::assertNull($this->lastUsedAt($id));

        $this->repository->recordUse($id);

        self::assertNotNull($this->lastUsedAt($id));
    }

    public function test_a_use_inside_the_window_writes_nothing(): void
    {
        $id = $this->mint('pr-12');
        $this->setLastUsedAt($id, '5 minutes');
        $before = $this->lastUsedAt($id);

        $this->repository->recordUse($id);

        self::assertSame($before, $this->lastUsedAt($id));
    }

    public function test_a_use_after_the_window_is_recorded(): void
    {
        $id = $this->mint('pr-12');
        $this->setLastUsedAt($id, '16 minutes');
        $before = $this->lastUsedAt($id);

        $this->repository->recordUse($id);

        self::assertNotSame($before, $this->lastUsedAt($id));
        self::assertEqualsWithDelta(time(), new \DateTimeImmutable((string) $this->lastUsedAt($id))->getTimestamp(), 5);
    }

    public function test_a_token_with_no_row_is_a_no_op(): void
    {
        $this->repository->recordUse('no-such-token');

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM oauth_preview_tokens'));
    }

    public function test_the_list_holds_the_live_tokens_of_the_person_newest_first(): void
    {
        $older = $this->mint('pr-12');
        $newer = $this->mint('pr-13');
        $this->connection->executeStatement(
            'UPDATE oauth_preview_tokens SET created_at = created_at - INTERVAL \'1 hour\' WHERE access_token_id = :id',
            ['id' => $older],
        );

        $rows = $this->repository->findLiveForUser($this->ownerId());

        self::assertSame([$newer, $older], array_map(static fn (PreviewTokenRow $row): string => $row->accessTokenId, $rows));
        self::assertSame('pr-13', $rows[0]->label);
        self::assertTrue($this->project->id?->equals($rows[0]->projectId));
        self::assertSame('Preview site', $rows[0]->projectName);
        self::assertNull($rows[0]->cardId);
        self::assertNull($rows[0]->lastUsedAt);
        self::assertGreaterThan(new \DateTimeImmutable(), $rows[0]->expiresAt);
        self::assertGreaterThan($rows[1]->createdAt, $rows[0]->createdAt);
    }

    public function test_the_list_leaves_out_revoked_expired_and_other_peoples_tokens(): void
    {
        $live = $this->mint('pr-12');
        $revoked = $this->mint('pr-13');
        $this->connection->executeStatement('UPDATE oauth2_access_token SET revoked = true WHERE identifier = :id', ['id' => $revoked]);
        $expired = $this->mint('pr-14');
        $this->connection->executeStatement(
            'UPDATE oauth2_access_token SET expiry = :at WHERE identifier = :id',
            ['at' => new \DateTimeImmutable('-1 minute')->format('Y-m-d H:i:s'), 'id' => $expired],
        );
        $stranger = $this->scenario->createUser('preview-list-stranger@example.com');
        $this->mint('pr-12', owner: $stranger, project: $this->scenario->createProject($stranger, 'Their site'));

        $rows = $this->repository->findLiveForUser($this->ownerId());

        self::assertSame([$live], array_map(static fn (PreviewTokenRow $row): string => $row->accessTokenId, $rows));
    }

    public function test_a_card_of_the_project_shows_its_number_and_title(): void
    {
        $cardId = $this->cardOf($this->project, 7);
        $this->mint('pr-12', $cardId);

        $rows = $this->repository->findLiveForUser($this->ownerId());

        self::assertCount(1, $rows);
        self::assertTrue($cardId->equals($rows[0]->cardId));
        self::assertSame(7, $rows[0]->cardNumber);
        self::assertSame('Preview it', $rows[0]->cardTitle);
    }

    public function test_a_card_that_no_longer_exists_reads_as_no_card(): void
    {
        $id = $this->mint('pr-12', $this->cardOf($this->project));
        $gone = Uuid::v7();
        $this->connection->executeStatement(
            'UPDATE oauth_preview_tokens SET card_id = :cardId WHERE access_token_id = :id',
            ['cardId' => $gone->toRfc4122(), 'id' => $id],
        );

        $rows = $this->repository->findLiveForUser($this->ownerId());

        self::assertCount(1, $rows);
        self::assertTrue($gone->equals($rows[0]->cardId));
        self::assertNull($rows[0]->cardNumber);
        self::assertNull($rows[0]->cardTitle);
    }

    public function test_a_card_of_another_project_reads_as_no_card(): void
    {
        $otherCard = $this->cardOf($this->scenario->createProject($this->owner, 'Other site'));
        $id = $this->mint('pr-12');
        $this->connection->executeStatement(
            'UPDATE oauth_preview_tokens SET card_id = :cardId WHERE access_token_id = :id',
            ['cardId' => $otherCard->toRfc4122(), 'id' => $id],
        );

        $rows = $this->repository->findLiveForUser($this->ownerId());

        self::assertCount(1, $rows);
        self::assertNull($rows[0]->cardNumber);
        self::assertNull($rows[0]->cardTitle);
    }

    public function test_the_card_list_holds_the_tokens_of_that_card_alone(): void
    {
        $cardId = $this->cardOf($this->project);
        $ofCard = $this->mint('pr-12', $cardId);
        $this->mint('pr-13', $this->cardOf($this->project, 2));
        $this->mint('pr-14');

        $rows = $this->repository->findLiveForUserAndCard($this->ownerId(), $cardId);

        self::assertSame([$ofCard], array_map(static fn (PreviewTokenRow $row): string => $row->accessTokenId, $rows));
    }

    private function ownerId(): Uuid
    {
        return $this->owner->id ?? throw new \LogicException('A persisted user has an id.');
    }

    private function lastUsedAt(string $id): ?string
    {
        $value = $this->connection->fetchOne('SELECT last_used_at FROM oauth_preview_tokens WHERE access_token_id = :id', ['id' => $id]);

        return \is_string($value) ? $value : null;
    }

    private function setLastUsedAt(string $id, string $ago): void
    {
        $this->connection->executeStatement(
            'UPDATE oauth_preview_tokens SET last_used_at = :at WHERE access_token_id = :id',
            ['at' => new \DateTimeImmutable('-'.$ago)->format('Y-m-d H:i:s'), 'id' => $id],
        );
    }
}
