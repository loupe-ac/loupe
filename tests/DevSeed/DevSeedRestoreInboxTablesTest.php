<?php

declare(strict_types=1);

namespace App\Tests\DevSeed;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('dev-seed-restore')]
final class DevSeedRestoreInboxTablesTest extends DevSeedTestCase
{
    /** @return iterable<string, array{string}> */
    public static function inboxTableDeletions(): iterable
    {
        yield 'every ask' => ['DELETE FROM inbox_asks'];
        yield 'every item' => ['DELETE FROM inbox_items'];
        yield 'every ask link' => ['DELETE FROM inbox_ask_items'];
        yield 'every card link' => ['DELETE FROM inbox_item_cards'];
        yield 'every document link' => ['DELETE FROM inbox_item_documents'];
        yield 'every review' => ['DELETE FROM inbox_reviews'];
        yield 'every action' => ['DELETE FROM inbox_actions'];
        yield 'every bridge outage' => ['DELETE FROM inbox_bridge_outages'];
    }

    #[DataProvider('inboxTableDeletions')]
    public function test_a_later_run_restores_a_whole_inbox_table_on_its_own(string $deletion): void
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
}
