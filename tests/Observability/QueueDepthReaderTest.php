<?php

declare(strict_types=1);

namespace App\Tests\Observability;

use App\Observability\QueueDepthReader;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Yaml\Yaml;

final class QueueDepthReaderTest extends KernelTestCase
{
    private Connection $connection;
    private QueueDepthReader $reader;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->reader = self::getContainer()->get(QueueDepthReader::class);
        $this->now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->connection->executeStatement('DELETE FROM messenger_messages');
    }

    public function test_every_known_queue_reports_zero_when_empty(): void
    {
        $depths = $this->reader->read($this->now);

        self::assertCount(5, $depths);
        foreach ($depths as $depth) {
            self::assertSame(0, $depth->pending);
            self::assertSame(0, $depth->oldestAgeSeconds);
        }
    }

    public function test_it_counts_due_messages_per_queue_with_the_age_of_the_oldest(): void
    {
        $this->insert('jobs', '-120 seconds');
        $this->insert('jobs', '-30 seconds');
        $this->insert('card_sweep', '-10 seconds');
        $this->insert('failed', '-600 seconds');

        $byQueue = [];
        foreach ($this->reader->read($this->now) as $depth) {
            $byQueue[$depth->queueName] = $depth;
        }

        self::assertSame(2, $byQueue['jobs']->pending);
        self::assertSame(120, $byQueue['jobs']->oldestAgeSeconds);
        self::assertSame('jobs', $byQueue['jobs']->transport);
        self::assertSame(1, $byQueue['card_sweep']->pending);
        self::assertSame(10, $byQueue['card_sweep']->oldestAgeSeconds);
        self::assertSame(1, $byQueue['failed']->pending);
        self::assertSame(600, $byQueue['failed']->oldestAgeSeconds);
        self::assertSame(0, $byQueue['card_eval']->pending);
    }

    public function test_it_skips_future_messages_and_live_claims_but_counts_expired_claims(): void
    {
        $this->insert('jobs', '+60 seconds');
        $this->insert('jobs', '-100 seconds', '-60 seconds');
        $this->insert('card_eval', '-200 seconds', '-4000 seconds');

        $byQueue = [];
        foreach ($this->reader->read($this->now) as $depth) {
            $byQueue[$depth->queueName] = $depth;
        }

        self::assertSame(0, $byQueue['jobs']->pending);
        self::assertSame(1, $byQueue['card_eval']->pending);
        self::assertSame(200, $byQueue['card_eval']->oldestAgeSeconds);
    }

    public function test_the_queue_map_covers_every_doctrine_transport(): void
    {
        $config = Yaml::parseFile(self::getContainer()->getParameter('kernel.project_dir').'/config/packages/messenger.yaml');
        $queues = [];
        foreach ($config['framework']['messenger']['transports'] as $transport) {
            $dsn = \is_array($transport) ? $transport['dsn'] : $transport;
            if (!str_starts_with((string) $dsn, 'doctrine://') && !str_contains((string) $dsn, 'MESSENGER_TRANSPORT_DSN')) {
                continue;
            }

            parse_str((string) parse_url((string) $dsn, \PHP_URL_QUERY), $query);
            $queues[] = $transport['options']['queue_name'] ?? $query['queue_name'] ?? 'default';
        }

        self::assertEqualsCanonicalizing($queues, array_keys(QueueDepthReader::TRANSPORT_BY_QUEUE));
    }

    private function insert(string $queue, string $availableOffset, ?string $deliveredOffset = null): void
    {
        $this->connection->insert(
            'messenger_messages',
            [
                'body' => '{}',
                'headers' => '{}',
                'queue_name' => $queue,
                'created_at' => $this->now,
                'available_at' => $this->now->modify($availableOffset),
                'delivered_at' => null === $deliveredOffset ? null : $this->now->modify($deliveredOffset),
            ],
            [
                'created_at' => Types::DATETIME_IMMUTABLE,
                'available_at' => Types::DATETIME_IMMUTABLE,
                'delivered_at' => Types::DATETIME_IMMUTABLE,
            ],
        );
    }
}
