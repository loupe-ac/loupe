<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Api\RateLimit\ApiBudget;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261101214533;
use DoctrineMigrations\Version20261101235138;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261101214533.php';
require_once __DIR__.'/../../migrations/Version20261101235138.php';

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

    /**
     * @param class-string<AbstractMigration> $migrationClass
     */
    #[DataProvider('seededDefaults')]
    public function test_each_migration_seeds_only_its_own_flag_at_the_coded_default(string $migrationClass, string $flag, string $otherFlag, string $value): void
    {
        $this->migrate($migrationClass);

        self::assertSame([['type' => 'int', 'value' => $value]], $this->rows($flag));
        self::assertSame([], $this->rows($otherFlag));
    }

    /**
     * @param class-string<AbstractMigration> $migrationClass
     */
    #[DataProvider('flagMigrations')]
    public function test_a_number_the_operator_already_set_is_left_alone(string $migrationClass, string $flag, string $otherFlag): void
    {
        $this->connection->executeStatement("INSERT INTO feature_flag (name, type, value, tags, options) VALUES (?, 'int', '1200', '[]', NULL)", [$flag]);

        $this->migrate($migrationClass);

        self::assertSame([['type' => 'int', 'value' => '1200']], $this->rows($flag));
        self::assertSame([], $this->rows($otherFlag));
    }

    /**
     * @param class-string<AbstractMigration> $migrationClass
     */
    #[DataProvider('flagMigrations')]
    public function test_down_removes_only_its_own_flag(string $migrationClass, string $flag, string $otherFlag): void
    {
        $this->migrate(Version20261101214533::class);
        $this->migrate(Version20261101235138::class);

        $this->execute($migrationClass, 'down');

        self::assertSame([], $this->rows($flag));
        self::assertCount(1, $this->rows($otherFlag));
    }

    /**
     * @return iterable<string, array{class-string<AbstractMigration>, string, string, string}>
     */
    public static function seededDefaults(): iterable
    {
        yield 'reads' => [Version20261101214533::class, ApiBudget::READS_FLAG, ApiBudget::WRITES_FLAG, '600'];
        yield 'writes' => [Version20261101235138::class, ApiBudget::WRITES_FLAG, ApiBudget::READS_FLAG, '120'];
    }

    /**
     * @return iterable<string, array{class-string<AbstractMigration>, string, string}>
     */
    public static function flagMigrations(): iterable
    {
        yield 'reads' => [Version20261101214533::class, ApiBudget::READS_FLAG, ApiBudget::WRITES_FLAG];
        yield 'writes' => [Version20261101235138::class, ApiBudget::WRITES_FLAG, ApiBudget::READS_FLAG];
    }

    /**
     * @param class-string<AbstractMigration> $migrationClass
     */
    private function migrate(string $migrationClass): void
    {
        $this->execute($migrationClass, 'up');
    }

    /**
     * @param class-string<AbstractMigration> $migrationClass
     * @param 'up'|'down'                     $direction
     */
    private function execute(string $migrationClass, string $direction): void
    {
        $migration = new $migrationClass($this->connection, new NullLogger());
        $migration->{$direction}(new Schema());
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
