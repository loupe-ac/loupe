<?php

declare(strict_types=1);

namespace App\Tests\DevSeed;

use App\DevSeed\DevSeedId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('dev-seed-restore')]
final class DevSeedRestoreInboxTest extends DevSeedTestCase
{
    public function test_a_later_run_restores_a_deleted_inbox_item_group_and_a_deleted_showcase_run(): void
    {
        $this->seed(self::DAY);
        $counts = $this->rowCounts();
        $connection = $this->em()->getConnection();
        $connection->executeStatement('DELETE FROM inbox_asks');
        $connection->executeStatement('DELETE FROM inbox_items');
        $connection->executeStatement("DELETE FROM bridge_worker_runs WHERE work_kind IN ('design-export', 'fix', 'review')");

        $this->seed(self::DAY);

        foreach (['inbox_items', 'inbox_asks', 'bridge_worker_runs'] as $table) {
            self::assertSame($counts[$table], $this->rowCounts()[$table], $table);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function partialInboxDeletions(): iterable
    {
        yield 'one ask, which keeps its item' => [\sprintf("DELETE FROM inbox_asks WHERE id = '%s'", DevSeedId::of('inbox/ask/retries'))];
        yield 'one item, which keeps its ask' => [\sprintf("DELETE FROM inbox_items WHERE id = '%s'", DevSeedId::of('inbox/item/retries'))];
        yield 'one of the two items of an ask' => [\sprintf("DELETE FROM inbox_items WHERE id = '%s'", DevSeedId::of('inbox/item/pull-request'))];
        yield 'one item of a completed ask' => [\sprintf("DELETE FROM inbox_items WHERE id = '%s'", DevSeedId::of('inbox/item/reviewed'))];
        yield 'the item of a session ask' => [\sprintf("DELETE FROM inbox_items WHERE id = '%s'", DevSeedId::of('inbox/item/session-checklist'))];
        yield 'the item of a command run' => [\sprintf("DELETE FROM inbox_items WHERE id = '%s'", DevSeedId::of('inbox/item/command-run-0'))];
        yield 'the ask of a command run' => [\sprintf("DELETE FROM inbox_asks WHERE id = '%s'", DevSeedId::of('inbox/ask/command-run-1'))];
    }

    #[DataProvider('partialInboxDeletions')]
    public function test_a_later_run_restores_each_inbox_record_on_its_own(string $deletion): void
    {
        $this->seed(self::DAY);
        $counts = $this->rowCounts();
        $connection = $this->em()->getConnection();
        self::assertGreaterThan(0, $connection->executeStatement($deletion));
        self::assertNotSame($counts, $this->rowCounts());

        $this->seed(self::DAY);

        self::assertSame($counts, $this->rowCounts());
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM inbox_items WHERE search_vector IS NULL'), 'A restored item has no search vector.');
    }

    public function test_a_later_run_restores_the_session_bridge_of_a_stored_ask(): void
    {
        $this->seed(self::DAY);
        $bridgeCount = 'SELECT COUNT(*) FROM bridges WHERE id = ?';
        $bridgeId = (string) DevSeedId::of('bridge/bridge/session');
        $connection = $this->em()->getConnection();
        self::assertSame(1, $connection->executeStatement('DELETE FROM bridges WHERE id = ?', [$bridgeId]));

        $this->seed(self::DAY);

        self::assertSame(1, (int) $connection->fetchOne($bridgeCount, [$bridgeId]));
    }
}
