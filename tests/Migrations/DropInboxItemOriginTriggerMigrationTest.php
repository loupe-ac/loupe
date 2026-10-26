<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261023173506;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261023173506.php';

/** Runs down() and up() inside the test's own transaction, which Postgres rolls back with the DDL. */
final class DropInboxItemOriginTriggerMigrationTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->connection = $em->getConnection();
    }

    public function test_up_drops_the_trigger_and_the_function(): void
    {
        $this->migrate(down: true);
        $this->migrate();

        self::assertSame(0, $this->rows("SELECT COUNT(*) FROM pg_trigger WHERE tgname = 'inbox_items_fill_origin'"));
        self::assertSame(0, $this->rows("SELECT COUNT(*) FROM pg_proc WHERE proname = 'inbox_items_fill_origin'"));
    }

    public function test_up_keeps_the_origin_of_inbox_items(): void
    {
        $this->migrate(down: true);
        $this->migrate();

        self::assertSame(1, $this->rows("SELECT COUNT(*) FROM information_schema.columns WHERE table_name = 'inbox_items' AND column_name = 'origin' AND is_nullable = 'NO'"));
    }

    public function test_down_restores_the_trigger(): void
    {
        $this->migrate(down: true);

        self::assertSame(1, $this->rows("SELECT COUNT(*) FROM pg_trigger WHERE tgname = 'inbox_items_fill_origin'"));
    }

    private function rows(string $sql): int
    {
        return (int) $this->connection->fetchOne($sql);
    }

    private function migrate(bool $down = false): void
    {
        $migration = new Version20261023173506($this->connection, new NullLogger());
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
