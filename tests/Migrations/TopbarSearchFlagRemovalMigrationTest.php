<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261028100116;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261028100116.php';

final class TopbarSearchFlagRemovalMigrationTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->connection = $em->getConnection();
    }

    public function test_the_flag_row_is_deleted_and_other_flags_stay(): void
    {
        $this->connection->executeStatement('DELETE FROM feature_flag WHERE name = ?', ['search.topbar.enabled']);
        $this->connection->executeStatement('INSERT INTO feature_flag (name, type, value, tags, options) VALUES (?, ?, ?, \'[]\', NULL)', ['search.topbar.enabled', 'bool', 'true']);
        $others = $this->flagCount('name <> \'search.topbar.enabled\'');
        self::assertSame(1, $this->flagCount('name = \'search.topbar.enabled\''));

        $this->migrate();

        self::assertSame(0, $this->flagCount('name = \'search.topbar.enabled\''));
        self::assertSame($others, $this->flagCount('name <> \'search.topbar.enabled\''));
    }

    public function test_an_instance_without_the_row_migrates_without_error(): void
    {
        $this->connection->executeStatement('DELETE FROM feature_flag WHERE name = ?', ['search.topbar.enabled']);

        $this->migrate();

        self::assertSame(0, $this->flagCount('name = \'search.topbar.enabled\''));
    }

    public function test_a_rollback_restores_the_flag_switched_off(): void
    {
        $this->connection->executeStatement('DELETE FROM feature_flag WHERE name = ?', ['search.topbar.enabled']);

        $this->migrate(down: true);

        self::assertSame('false', $this->connection->fetchOne('SELECT value FROM feature_flag WHERE name = ?', ['search.topbar.enabled']));
    }

    private function migrate(bool $down = false): void
    {
        $migration = new Version20261028100116($this->connection, new NullLogger());
        self::assertInstanceOf(AbstractMigration::class, $migration);
        $down ? $migration->down(new Schema()) : $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    private function flagCount(string $where): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM feature_flag WHERE '.$where);
    }
}
