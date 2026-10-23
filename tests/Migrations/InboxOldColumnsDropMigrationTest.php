<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxCardWatch;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261023173822;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

require_once __DIR__.'/../../migrations/Version20261023173822.php';

/** Runs down() and up() inside the test's own transaction, which Postgres rolls back with the DDL. */
final class InboxOldColumnsDropMigrationTest extends KernelTestCase
{
    use InboxFixtures;

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->connection = $em->getConnection();
    }

    public function test_the_schema_has_none_of_the_old_columns_the_fill_function_or_the_settings_table(): void
    {
        self::assertSame(0, $this->columns('board_cards', ['markers', 'markers_due_at']));
        self::assertSame(0, $this->columns('forge_pull_requests', ['waits_for_approval_since']));
        self::assertSame(0, $this->columns('inbox_card_waits', ['trigger', 'reason']));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM pg_proc WHERE proname = 'inbox_card_waits_fill'"));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM information_schema.tables WHERE table_name = 'inbox_project_settings'"));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM pg_indexes WHERE indexname IN ('idx_board_cards_markers_due', 'idx_board_cards_markers_missing')"));
    }

    public function test_a_card_wait_can_be_stored_with_no_trigger(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $project = $this->project($em, $this->owner($em, 'drop-columns'), 'drop-columns');
        $item = new InboxItem(project: $project, number: 1, kind: InboxItemKind::Wait, title: '#1 Ship it', blocking: true, origin: InboxAskOrigin::Loupe);
        $em->persist($item);
        $watch = new InboxCardWatch($item, Uuid::v7(), 1);
        $em->persist($watch);
        $em->flush();

        $this->connection->executeStatement(
            "INSERT INTO inbox_card_waits (id, watch_id, type, marker_key, started_at, spell_started_at) VALUES (:id, :watch, 'card', 'nothing-happened', '2026-10-20 09:00:00', '2026-10-20 09:00:00')",
            ['id' => (string) Uuid::v7(), 'watch' => (string) $watch->id],
        );

        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM inbox_card_waits WHERE type = 'card'"));
    }

    public function test_down_brings_the_old_columns_and_the_settings_table_back_and_up_drops_them_again(): void
    {
        $this->migrate(down: true);

        self::assertSame(2, $this->columns('board_cards', ['markers', 'markers_due_at']));
        self::assertSame(1, $this->columns('forge_pull_requests', ['waits_for_approval_since']));
        self::assertSame(2, $this->columns('inbox_card_waits', ['trigger', 'reason']));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM information_schema.tables WHERE table_name = 'inbox_project_settings'"));

        $this->migrate();

        self::assertSame(0, $this->columns('board_cards', ['markers', 'markers_due_at']));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM information_schema.tables WHERE table_name = 'inbox_project_settings'"));
    }

    public function test_after_down_a_wait_insert_that_names_no_trigger_or_reason_gets_both_from_the_fill_function(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $project = $this->project($em, $this->owner($em, 'drop-columns-down'), 'drop-columns-down');
        $item = new InboxItem(project: $project, number: 1, kind: InboxItemKind::Wait, title: '#1 Ship it', blocking: true, origin: InboxAskOrigin::Loupe);
        $em->persist($item);
        $watch = new InboxCardWatch($item, Uuid::v7(), 1);
        $em->persist($watch);
        $em->flush();

        $this->migrate(down: true);
        $id = (string) Uuid::v7();
        $this->connection->executeStatement(
            "INSERT INTO inbox_card_waits (id, watch_id, type, pull_request_id, head_sha, started_at) VALUES (:id, :watch, 'pull-request', :pullRequest, 'abc123', '2026-10-20 09:00:00')",
            ['id' => $id, 'watch' => (string) $watch->id, 'pullRequest' => (string) Uuid::v7()],
        );

        self::assertSame(
            ['trigger' => 'pull-request-ready', 'reason' => 'waiting-for-review', 'marker_key' => 'waits-for-approval'],
            $this->connection->fetchAssociative('SELECT "trigger", reason, marker_key FROM inbox_card_waits WHERE id = :id', ['id' => $id]),
        );

        $this->migrate();

        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM pg_proc WHERE proname = 'inbox_card_waits_fill'"));
    }

    /** @param list<string> $names */
    private function columns(string $table, array $names): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_name = :table AND column_name IN (:names)',
            ['table' => $table, 'names' => $names],
            ['names' => ArrayParameterType::STRING],
        );
    }

    private function migrate(bool $down = false): void
    {
        $migration = new Version20261023173822($this->connection, new NullLogger());
        if ($down) {
            $migration->down(new Schema());
        } else {
            $migration->up(new Schema());
        }
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
