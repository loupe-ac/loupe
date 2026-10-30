<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Repository;

use App\Module\Workflow\Repository\WorkflowQueuedEvaluationRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class WorkflowQueuedEvaluationRepositoryTest extends KernelTestCase
{
    private const string NOW = '2026-10-30 12:00:00';

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_a_claim_with_no_row_wins(): void
    {
        self::assertTrue($this->repository()->claim(Uuid::v7(), new \DateTimeImmutable(self::NOW)));
    }

    public function test_a_claim_against_a_fresh_row_loses(): void
    {
        $card = Uuid::v7();
        $repository = $this->repository();
        $repository->claim($card, new \DateTimeImmutable(self::NOW));

        self::assertFalse($repository->claim($card, new \DateTimeImmutable('2026-10-30 12:05:00')));
        self::assertSame(1, $this->rows($card));
    }

    public function test_a_claim_against_a_stale_row_wins_and_refreshes_it(): void
    {
        $card = Uuid::v7();
        $repository = $this->repository();
        $repository->claim($card, new \DateTimeImmutable(self::NOW));

        self::assertTrue($repository->claim($card, new \DateTimeImmutable('2026-10-30 12:11:00')));
        self::assertFalse($repository->claim($card, new \DateTimeImmutable('2026-10-30 12:12:00')));
    }

    public function test_a_release_lets_the_next_claim_win(): void
    {
        $card = Uuid::v7();
        $repository = $this->repository();
        $repository->claim($card, new \DateTimeImmutable(self::NOW));

        $repository->release($card);

        self::assertSame(0, $this->rows($card));
        self::assertTrue($repository->claim($card, new \DateTimeImmutable(self::NOW)));
    }

    public function test_a_claim_in_a_rolled_back_transaction_leaves_no_row(): void
    {
        $card = Uuid::v7();
        $connection = $this->connection();
        $connection->beginTransaction();
        $this->repository()->claim($card, new \DateTimeImmutable(self::NOW));
        $connection->rollBack();

        self::assertSame(0, $this->rows($card));
        self::assertTrue($this->repository()->claim($card, new \DateTimeImmutable(self::NOW)));
    }

    private function rows(Uuid $card): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM workflow_queued_evaluations WHERE card_id = :card', ['card' => $card->toRfc4122()]);
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    private function repository(): WorkflowQueuedEvaluationRepository
    {
        $repository = self::getContainer()->get(WorkflowQueuedEvaluationRepository::class);
        self::assertInstanceOf(WorkflowQueuedEvaluationRepository::class, $repository);

        return $repository;
    }
}
