<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261101220000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261101220000.php';

/** Runs down() and up() inside the test's own transaction, which Postgres rolls back with the DDL. */
final class InboxTriggerDropMigrationTest extends KernelTestCase
{
    use InboxFixtures;

    private EntityManagerInterface $em;

    private Connection $connection;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
    }

    public function test_the_schema_has_none_of_the_triggers_the_functions_or_the_old_columns(): void
    {
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM pg_trigger WHERE tgname IN ('inbox_items_map_old_writes', 'inbox_reviews_map_old_writes')"));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM pg_proc WHERE proname IN ('inbox_items_map_old_writes', 'inbox_reviews_map_old_writes', 'inbox_items_close_record')"));
        self::assertSame(0, $this->columns('inbox_items', ['answer_text', 'close_note', 'responded_through_agent']));
        self::assertSame(0, $this->columns('inbox_reviews', ['note']));
    }

    public function test_down_brings_the_old_columns_back_filled_from_the_close_record_and_up_drops_them_again(): void
    {
        $project = $this->project($this->em, $this->owner($this->em, 'trigger-drop-down'), 'trigger-drop-down');
        $question = new InboxItem(project: $project, number: 1, kind: InboxItemKind::Question, title: 'Format?', blocking: true, origin: InboxAskOrigin::Agent);
        $this->em->persist($question);
        $this->em->flush();
        $this->connection->executeStatement("UPDATE inbox_items SET state = 'resolved', closed_by = 'owner-through-agent', note = 'CSV' WHERE id = ?", [(string) $question->id]);

        $this->migrate(down: true);

        self::assertSame(3, $this->columns('inbox_items', ['answer_text', 'close_note', 'responded_through_agent']));
        self::assertSame(1, $this->columns('inbox_reviews', ['note']));
        self::assertSame(
            ['CSV', null, true],
            array_values($this->connection->fetchAssociative('SELECT answer_text, close_note, responded_through_agent FROM inbox_items WHERE id = ?', [(string) $question->id]) ?: []),
        );

        $this->migrate();

        self::assertSame(0, $this->columns('inbox_items', ['answer_text', 'close_note', 'responded_through_agent']));
        self::assertSame(0, $this->columns('inbox_reviews', ['note']));
    }

    public function test_up_maps_a_row_left_in_an_old_state_and_sets_dismissible_false_on_a_notice(): void
    {
        $project = $this->project($this->em, $this->owner($this->em, 'trigger-drop-up'), 'trigger-drop-up');
        $withdrawn = new InboxItem(project: $project, number: 1, kind: InboxItemKind::Question, title: 'Format?', blocking: true, origin: InboxAskOrigin::Agent);
        $notice = new InboxItem(project: $project, number: 2, kind: InboxItemKind::Notice, title: 'Heads up', blocking: false, origin: InboxAskOrigin::Loupe);
        $this->em->persist($withdrawn);
        $this->em->persist($notice);
        $this->em->flush();
        $this->migrate(down: true);
        $this->connection->executeStatement("UPDATE inbox_items SET state = 'withdrawn' WHERE id = ?", [(string) $withdrawn->id]);
        $this->connection->executeStatement('UPDATE inbox_items SET dismissible = true WHERE id = ?', [(string) $notice->id]);

        $this->migrate();

        self::assertSame(
            ['cancelled', 'owner'],
            array_values($this->connection->fetchAssociative('SELECT state, closed_by FROM inbox_items WHERE id = ?', [(string) $withdrawn->id]) ?: []),
        );
        self::assertFalse((bool) $this->connection->fetchOne('SELECT dismissible FROM inbox_items WHERE id = ?', [(string) $notice->id]));
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
        $migration = new Version20261101220000($this->connection, new NullLogger());
        $down ? $migration->down(new Schema()) : $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
