<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\NotNullConstraintViolationException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261022094231;
use PHPUnit\Framework\Attributes\TestWith;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

require_once __DIR__.'/../../migrations/Version20261022094231.php';

/** Runs down() and up() inside the test's own transaction, which Postgres rolls back with the DDL. */
final class InboxItemOriginMigrationTest extends KernelTestCase
{
    use InboxFixtures;

    private Connection $connection;
    private string $sourceId;
    private int $number = 100;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->connection = $em->getConnection();

        $item = new InboxItem(project: $this->project($em, $this->owner($em, 'item-origin'), 'item-origin'), number: 1, kind: InboxItemKind::Question, title: 'Which option?', blocking: true, origin: InboxAskOrigin::Agent);
        $em->persist($item);
        $em->flush();
        $this->sourceId = (string) $item->id;
    }

    #[TestWith(['wait', 'loupe'])]
    #[TestWith(['notice', 'loupe'])]
    #[TestWith(['workflow', 'loupe'])]
    #[TestWith(['review', 'agent'])]
    #[TestWith(['question', 'agent'])]
    #[TestWith(['todo', 'agent'])]
    public function test_up_fills_the_origin_of_an_existing_item_from_its_kind(string $kind, string $origin): void
    {
        $this->migrate(down: true);
        $id = $this->insert($kind);

        $this->migrate();

        self::assertSame($origin, $this->connection->fetchOne('SELECT origin FROM inbox_items WHERE id = :id', ['id' => $id]));
    }

    #[TestWith(['wait', 'loupe'])]
    #[TestWith(['notice', 'loupe'])]
    #[TestWith(['workflow', 'loupe'])]
    #[TestWith(['review', 'agent'])]
    #[TestWith(['question', 'agent'])]
    #[TestWith(['todo', 'agent'])]
    public function test_an_insert_of_the_previous_code_gets_its_origin_from_its_kind(string $kind, string $origin): void
    {
        $id = $this->insert($kind);

        self::assertSame($origin, $this->connection->fetchOne('SELECT origin FROM inbox_items WHERE id = :id', ['id' => $id]));
    }

    public function test_an_insert_that_sets_the_origin_keeps_it(): void
    {
        $id = $this->insert('review', InboxAskOrigin::Loupe->value);

        self::assertSame('loupe', $this->connection->fetchOne('SELECT origin FROM inbox_items WHERE id = :id', ['id' => $id]));
    }

    public function test_the_origin_cannot_be_null(): void
    {
        $this->expectException(NotNullConstraintViolationException::class);
        $this->connection->executeStatement('UPDATE inbox_items SET origin = NULL WHERE id = :id', ['id' => $this->sourceId]);
    }

    public function test_down_drops_the_trigger_the_function_and_the_column(): void
    {
        $this->migrate(down: true);

        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM pg_proc WHERE proname = 'inbox_items_fill_origin'"));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM pg_trigger WHERE tgname = 'inbox_items_fill_origin'"));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM information_schema.columns WHERE table_name = 'inbox_items' AND column_name = 'origin'"));
    }

    /** Copies the stored item with a new id, number and kind. It leaves the origin out unless one is given. */
    private function insert(string $kind, ?string $origin = null): string
    {
        $columns = $this->connection->fetchFirstColumn("SELECT column_name FROM information_schema.columns WHERE table_name = 'inbox_items' AND column_name NOT IN ('id', 'kind', 'number', 'origin', 'search_vector')");
        $list = implode(', ', array_map(static fn (string $name): string => '"'.$name.'"', $columns));
        $id = (string) Uuid::v7();
        $this->connection->executeStatement(
            \sprintf('INSERT INTO inbox_items (id, kind, number, %s%s) SELECT :id, :kind, :number, %s%s FROM inbox_items WHERE id = :source', null === $origin ? '' : 'origin, ', $list, null === $origin ? '' : ':origin, ', $list),
            ['id' => $id, 'kind' => $kind, 'number' => ++$this->number, 'source' => $this->sourceId, ...(null === $origin ? [] : ['origin' => $origin])],
        );

        return $id;
    }

    private function migrate(bool $down = false): void
    {
        $migration = new Version20261022094231($this->connection, new NullLogger());
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
