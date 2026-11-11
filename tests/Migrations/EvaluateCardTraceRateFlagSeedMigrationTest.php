<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Observability\TracesSampler;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261111003120;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261111003120.php';

final class EvaluateCardTraceRateFlagSeedMigrationTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->connection = $em->getConnection();
        $this->connection->executeStatement('DELETE FROM feature_flag WHERE name = ?', [TracesSampler::EVALUATE_CARD_RATE_FLAG]);
    }

    public function test_an_upgraded_instance_gets_the_default_rate(): void
    {
        $this->migrate();

        self::assertSame([['type' => 'int', 'value' => '10']], $this->rows());
    }

    public function test_an_existing_row_is_left_alone(): void
    {
        $this->connection->executeStatement("INSERT INTO feature_flag (name, type, value, tags, options) VALUES (?, 'int', '2', '[]', NULL)", [TracesSampler::EVALUATE_CARD_RATE_FLAG]);

        $this->migrate();

        self::assertSame([['type' => 'int', 'value' => '2']], $this->rows());
    }

    public function test_the_down_migration_removes_the_row(): void
    {
        $this->migrate();
        self::assertCount(1, $this->rows());

        $migration = new Version20261111003120($this->connection, new NullLogger());
        $migration->down(new Schema());
        $this->execute($migration);

        self::assertSame([], $this->rows());
    }

    private function migrate(): void
    {
        $migration = new Version20261111003120($this->connection, new NullLogger());
        $migration->up(new Schema());
        $this->execute($migration);
    }

    private function execute(Version20261111003120 $migration): void
    {
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return $this->connection->fetchAllAssociative('SELECT type, value::text AS value FROM feature_flag WHERE name = ?', [TracesSampler::EVALUATE_CARD_RATE_FLAG]);
    }
}
