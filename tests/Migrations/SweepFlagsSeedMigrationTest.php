<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Workflow\Install\WorkflowInstallFlags;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261117040000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261117040000.php';

final class SweepFlagsSeedMigrationTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->connection = $em->getConnection();
        $this->connection->executeStatement('DELETE FROM feature_flag WHERE name IN (?, ?)', $this->names());
    }

    public function test_an_instance_that_upgrades_gets_the_sweep_on_and_the_interval_at_24_hours(): void
    {
        $this->migrate();

        self::assertSame(
            [
                WorkflowInstallFlags::FLAG_CHANGE_DRIVEN_SWEEP => ['bool', 'true'],
                WorkflowInstallFlags::FLAG_FULL_SWEEP_INTERVAL_HOURS => ['int', '24'],
            ],
            $this->rows(),
        );
    }

    public function test_an_existing_value_stays(): void
    {
        $this->connection->executeStatement("INSERT INTO feature_flag (name, type, value, tags, options) VALUES (?, 'int', '6', '[]', NULL)", [WorkflowInstallFlags::FLAG_FULL_SWEEP_INTERVAL_HOURS]);

        $this->migrate();

        self::assertSame(['int', '6'], $this->rows()[WorkflowInstallFlags::FLAG_FULL_SWEEP_INTERVAL_HOURS]);
    }

    private function migrate(): void
    {
        $migration = new Version20261117040000($this->connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    /** @return list<string> */
    private function names(): array
    {
        return [WorkflowInstallFlags::FLAG_CHANGE_DRIVEN_SWEEP, WorkflowInstallFlags::FLAG_FULL_SWEEP_INTERVAL_HOURS];
    }

    /** @return array<string, array{string, string}> */
    private function rows(): array
    {
        $rows = [];
        foreach ($this->connection->fetchAllAssociative('SELECT name, type, value::text AS value FROM feature_flag WHERE name IN (?, ?) ORDER BY name', $this->names()) as $row) {
            $rows[(string) $row['name']] = [(string) $row['type'], (string) $row['value']];
        }

        return $rows;
    }
}
