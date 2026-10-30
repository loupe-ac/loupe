<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Bridge\Service\InteractiveStaleClose;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261030133339;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261030133339.php';

final class InteractiveStaleAfterFlagMigrationTest extends KernelTestCase
{
    private const string OLD_FLAG = 'bridge.interactive_stale_close_enabled';

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->connection = $em->getConnection();
        $this->connection->executeStatement('DELETE FROM feature_flag WHERE name IN (?, ?)', [self::OLD_FLAG, InteractiveStaleClose::FLAG]);
    }

    public function test_an_instance_with_the_sweep_on_gets_twelve_hours(): void
    {
        $this->insertOldFlag('true');

        $this->migrate();

        self::assertSame([['type' => 'int', 'value' => '43200']], $this->rows(InteractiveStaleClose::FLAG));
        self::assertSame([], $this->rows(self::OLD_FLAG));
    }

    public function test_an_instance_with_the_sweep_off_keeps_it_off(): void
    {
        $this->insertOldFlag('false');

        $this->migrate();

        self::assertSame([['type' => 'int', 'value' => '0']], $this->rows(InteractiveStaleClose::FLAG));
        self::assertSame([], $this->rows(self::OLD_FLAG));
    }

    public function test_an_instance_without_either_flag_gets_twelve_hours(): void
    {
        $this->migrate();

        self::assertSame([['type' => 'int', 'value' => '43200']], $this->rows(InteractiveStaleClose::FLAG));
    }

    public function test_an_existing_threshold_is_left_alone(): void
    {
        $this->connection->executeStatement("INSERT INTO feature_flag (name, type, value, tags, options) VALUES (?, 'int', '600', '[]', NULL)", [InteractiveStaleClose::FLAG]);

        $this->migrate();

        self::assertSame([['type' => 'int', 'value' => '600']], $this->rows(InteractiveStaleClose::FLAG));
    }

    private function insertOldFlag(string $value): void
    {
        $this->connection->executeStatement("INSERT INTO feature_flag (name, type, value, tags, options) VALUES (?, 'bool', ?, '[]', NULL)", [self::OLD_FLAG, $value]);
    }

    private function migrate(): void
    {
        $migration = new Version20261030133339($this->connection, new NullLogger());
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
