<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261017154818;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261017154818.php';

final class MessengerQueueRenameMigrationTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->connection = $em->getConnection();
        $this->connection->executeStatement('DELETE FROM messenger_messages');
    }

    public function test_up_moves_each_old_queue_name_to_its_new_name_and_leaves_failed_alone(): void
    {
        $this->seed(['default', 'workflow', 'async_low', 'failed']);

        $this->migrate('up');

        self::assertSame(['card_eval', 'card_sweep', 'failed', 'jobs'], $this->queues());
    }

    public function test_down_moves_the_new_names_back(): void
    {
        $this->seed(['jobs', 'card_eval', 'card_sweep', 'failed']);

        $this->migrate('down');

        self::assertSame(['async_low', 'default', 'failed', 'workflow'], $this->queues());
    }

    public function test_running_up_twice_changes_nothing_the_second_time(): void
    {
        $this->seed(['default', 'workflow', 'async_low']);

        $this->migrate('up');
        $this->migrate('up');

        self::assertSame(['card_eval', 'card_sweep', 'jobs'], $this->queues());
    }

    /** @param list<string> $queues */
    private function seed(array $queues): void
    {
        foreach ($queues as $queue) {
            $this->connection->insert('messenger_messages', [
                'body' => '{}',
                'headers' => '{}',
                'queue_name' => $queue,
                'created_at' => '2026-10-17 10:00:00',
                'available_at' => '2026-10-17 10:00:00',
            ]);
        }
    }

    private function migrate(string $direction): void
    {
        $migration = new Version20261017154818($this->connection, new NullLogger());
        'up' === $direction ? $migration->up(new Schema()) : $migration->down(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    /** @return list<string> */
    private function queues(): array
    {
        $queues = $this->connection->fetchFirstColumn('SELECT queue_name FROM messenger_messages');
        sort($queues);

        return array_map(strval(...), $queues);
    }
}
