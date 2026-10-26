<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261026150735;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261026150735.php';

/** Runs down() and up() inside the test's own transaction, which Postgres rolls back with the DDL. */
final class InboxAskOriginDropMigrationTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->connection = $em->getConnection();
    }

    public function test_the_schema_has_no_origin_column_on_inbox_asks(): void
    {
        self::assertSame(0, $this->columns());
    }

    public function test_down_restores_the_column_with_its_default_and_up_drops_it_again(): void
    {
        $this->migrate(down: true);

        self::assertSame(1, $this->columns());
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM information_schema.columns WHERE table_name = 'inbox_asks' AND column_name = 'origin' AND column_default LIKE '''agent''%' AND is_nullable = 'NO' AND character_maximum_length = 20"));

        $this->migrate();

        self::assertSame(0, $this->columns());
    }

    private function columns(): int
    {
        return (int) $this->connection->fetchOne("SELECT COUNT(*) FROM information_schema.columns WHERE table_name = 'inbox_asks' AND column_name = 'origin'");
    }

    private function migrate(bool $down = false): void
    {
        $migration = new Version20261026150735($this->connection, new NullLogger());
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
