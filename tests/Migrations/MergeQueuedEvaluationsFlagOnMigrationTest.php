<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Workflow\Install\WorkflowInstallFlags;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261116190000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261116190000.php';

final class MergeQueuedEvaluationsFlagOnMigrationTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->executeStatement('DELETE FROM feature_flag WHERE name = ?', [WorkflowInstallFlags::FLAG_MERGE_QUEUED_EVALUATIONS]);
    }

    public function test_a_flag_that_is_off_goes_on(): void
    {
        $this->seed('false');

        $this->migrate('up');

        self::assertSame('true', $this->value());
    }

    public function test_a_flag_that_is_on_stays_on(): void
    {
        $this->seed('true');

        $this->migrate('up');

        self::assertSame('true', $this->value());
    }

    public function test_a_missing_row_stays_missing(): void
    {
        $this->migrate('up');

        self::assertFalse($this->value());
    }

    public function test_the_down_migration_switches_the_flag_off(): void
    {
        $this->seed('true');

        $this->migrate('down');

        self::assertSame('false', $this->value());
    }

    private function seed(string $value): void
    {
        $this->connection->executeStatement("INSERT INTO feature_flag (name, type, value, tags, options) VALUES (?, 'bool', ?, '[]', NULL)", [WorkflowInstallFlags::FLAG_MERGE_QUEUED_EVALUATIONS, $value]);
    }

    private function migrate(string $direction): void
    {
        $migration = new Version20261116190000($this->connection, new NullLogger());
        'up' === $direction ? $migration->up(new Schema()) : $migration->down(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    private function value(): string|false
    {
        return $this->connection->fetchOne('SELECT value::text FROM feature_flag WHERE name = ?', [WorkflowInstallFlags::FLAG_MERGE_QUEUED_EVALUATIONS]);
    }
}
