<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Api\RateLimit\ApiBudget;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261101214533;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261101214533.php';

final class ApiBudgetFlagsMigrationTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->connection = $em->getConnection();
        $this->connection->executeStatement('DELETE FROM feature_flag WHERE name IN (?, ?)', [ApiBudget::READS_FLAG, ApiBudget::WRITES_FLAG]);
    }

    public function test_an_upgraded_instance_gets_both_budgets_at_the_coded_defaults(): void
    {
        $this->migrate();

        self::assertSame([['type' => 'int', 'value' => '600']], $this->rows(ApiBudget::READS_FLAG));
        self::assertSame([['type' => 'int', 'value' => '120']], $this->rows(ApiBudget::WRITES_FLAG));
    }

    public function test_a_number_the_operator_already_set_is_left_alone(): void
    {
        $this->connection->executeStatement("INSERT INTO feature_flag (name, type, value, tags, options) VALUES (?, 'int', '1200', '[]', NULL)", [ApiBudget::READS_FLAG]);

        $this->migrate();

        self::assertSame([['type' => 'int', 'value' => '1200']], $this->rows(ApiBudget::READS_FLAG));
    }

    private function migrate(): void
    {
        $migration = new Version20261101214533($this->connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $name): array
    {
        return $this->connection->fetchAllAssociative('SELECT type, value #>> \'{}\' AS value FROM feature_flag WHERE name = ?', [$name]);
    }
}
