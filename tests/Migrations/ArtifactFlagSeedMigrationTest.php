<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261011213101;
use DoctrineMigrations\Version20261011213102;
use DoctrineMigrations\Version20261011213103;
use DoctrineMigrations\Version20261011235501;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261011213101.php';
require_once __DIR__.'/../../migrations/Version20261011213102.php';
require_once __DIR__.'/../../migrations/Version20261011213103.php';
require_once __DIR__.'/../../migrations/Version20261011235501.php';

final class ArtifactFlagSeedMigrationTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->connection = $em->getConnection();
    }

    /** @return iterable<string, array{class-string<AbstractMigration>, string, string, string}> */
    public static function flags(): iterable
    {
        yield 'enabled' => [Version20261011213101::class, 'artifacts.enabled', 'bool', 'false'];
        yield 'size limit' => [Version20261011213102::class, 'artifacts.max_version_bytes', 'int', '5242880'];
        yield 'file limit' => [Version20261011213103::class, 'artifacts.max_files', 'int', '50'];
        yield 'allowed origins' => [Version20261011235501::class, 'artifacts.allowed_origins', 'string', '"https://fonts.googleapis.com https://fonts.gstatic.com https://cdn.jsdelivr.net https://unpkg.com https://cdnjs.cloudflare.com"'];
    }

    /** @param class-string<AbstractMigration> $migration */
    #[DataProvider('flags')]
    public function test_an_instance_without_the_flag_gets_its_default(string $migration, string $name, string $type, string $value): void
    {
        $this->connection->executeStatement('DELETE FROM feature_flag WHERE name = ?', [$name]);

        $this->migrate($migration);

        self::assertSame([['type' => $type, 'value' => $value]], $this->rows($name));
    }

    /** @param class-string<AbstractMigration> $migration */
    #[DataProvider('flags')]
    public function test_an_existing_row_is_left_alone(string $migration, string $name, string $type, string $default): void
    {
        $this->connection->executeStatement('DELETE FROM feature_flag WHERE name = ?', [$name]);
        $this->connection->executeStatement('INSERT INTO feature_flag (name, type, value, tags, options) VALUES (?, ?, ?, \'[]\', NULL)', [$name, $type, 'bool' === $type ? 'true' : '7']);

        self::assertNotSame($default, 'bool' === $type ? 'true' : '7');
        $this->migrate($migration);

        self::assertSame([['type' => $type, 'value' => 'bool' === $type ? 'true' : '7']], $this->rows($name));
    }

    /** @param class-string<AbstractMigration> $class */
    private function migrate(string $class): void
    {
        $migration = new $class($this->connection, new NullLogger());
        self::assertInstanceOf(AbstractMigration::class, $migration);
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $name): array
    {
        return $this->connection->fetchAllAssociative('SELECT type, value::text AS value FROM feature_flag WHERE name = ?', [$name]);
    }
}
