<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Account\Command\SeedInstallFlagsCommand;
use App\Module\Account\Command\SeedInstallFlagsHandler;
use App\Module\Account\Entity\User;
use App\Module\Workflow\Install\WorkflowInstallFlags;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261030150642;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261030150642.php';

final class MergeQueuedEvaluationsFlagSeedMigrationTest extends KernelTestCase
{
    private Connection $connection;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->executeStatement('DELETE FROM feature_flag WHERE name = ?', [WorkflowInstallFlags::FLAG_MERGE_QUEUED_EVALUATIONS]);
    }

    public function test_an_instance_with_a_human_account_gets_the_flag_off(): void
    {
        $this->addUser();

        $this->migrate();

        self::assertSame([['type' => 'bool', 'value' => 'false']], $this->rows());
    }

    public function test_a_fresh_install_gets_no_row_from_the_migration_and_the_flag_on_from_install(): void
    {
        $this->connection->executeStatement('DELETE FROM users WHERE id NOT IN (?)', [User::SYSTEM_IDS], [ArrayParameterType::STRING]);

        $this->migrate();

        self::assertSame([], $this->rows());

        $handler = self::getContainer()->get(SeedInstallFlagsHandler::class);
        $handler(new SeedInstallFlagsCommand(0, true, false, 14, null, false, false));

        self::assertSame([['type' => 'bool', 'value' => 'true']], $this->rows());
    }

    public function test_an_existing_row_is_left_alone(): void
    {
        $this->addUser();
        $this->connection->executeStatement("INSERT INTO feature_flag (name, type, value, tags, options) VALUES (?, 'bool', 'true', '[]', NULL)", [WorkflowInstallFlags::FLAG_MERGE_QUEUED_EVALUATIONS]);

        $this->migrate();

        self::assertSame([['type' => 'bool', 'value' => 'true']], $this->rows());
    }

    public function test_the_down_migration_removes_the_row(): void
    {
        $this->addUser();
        $this->migrate();
        self::assertCount(1, $this->rows());

        $migration = new Version20261030150642($this->connection, new NullLogger());
        $migration->down(new Schema());
        $this->execute($migration);

        self::assertSame([], $this->rows());
    }

    private function addUser(): void
    {
        $this->em->persist(new User(fullName: 'Test User', email: 'owner@example.com', password: 'x'));
        $this->em->flush();
    }

    private function migrate(): void
    {
        $migration = new Version20261030150642($this->connection, new NullLogger());
        $migration->up(new Schema());
        $this->execute($migration);
    }

    private function execute(Version20261030150642 $migration): void
    {
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return $this->connection->fetchAllAssociative('SELECT type, value::text AS value FROM feature_flag WHERE name = ?', [WorkflowInstallFlags::FLAG_MERGE_QUEUED_EVALUATIONS]);
    }
}
