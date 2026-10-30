<?php

declare(strict_types=1);

namespace App\Observability;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * Counts the waiting messages of each queue. A message waits when it is due and
 * no worker holds a live claim on it. The rule mirrors the Doctrine transport
 * and the health-check bundle's WorkerCheck, so a change to one needs the other.
 */
final readonly class QueueDepthReader
{
    /** Queue name in `messenger_messages` to the transport that owns it. Each transport sets its queue name to its own name. */
    public const array TRANSPORT_BY_QUEUE = [
        'interactive' => 'interactive',
        'jobs' => 'jobs',
        'card_eval' => 'card_eval',
        'card_sweep' => 'card_sweep',
        'failed' => 'failed',
    ];

    /** The Doctrine transport's default `redeliver_timeout`. */
    public const int REDELIVER_TIMEOUT_SECONDS = 3600;

    private const string SQL = 'SELECT queue_name, COUNT(*) AS pending, EXTRACT(EPOCH FROM (:now - MIN(available_at)))::int AS oldest_age FROM messenger_messages WHERE available_at <= :now AND (delivered_at IS NULL OR delivered_at < :redeliverLimit) GROUP BY queue_name';

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return list<QueueDepth> one entry per known queue, zero when it holds no waiting message
     */
    public function read(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $rows = $this->connection->fetchAllAssociative(
            self::SQL,
            ['now' => $now, 'redeliverLimit' => $now->modify(\sprintf('-%d seconds', self::REDELIVER_TIMEOUT_SECONDS))],
            ['now' => Types::DATETIME_IMMUTABLE, 'redeliverLimit' => Types::DATETIME_IMMUTABLE],
        );

        $byQueue = [];
        foreach ($rows as $row) {
            $byQueue[(string) $row['queue_name']] = $row;
        }

        $depths = [];
        foreach (self::TRANSPORT_BY_QUEUE as $queue => $transport) {
            $row = $byQueue[$queue] ?? null;
            $depths[] = new QueueDepth(
                $queue,
                $transport,
                null === $row ? 0 : (int) $row['pending'],
                null === $row ? 0 : max(0, (int) $row['oldest_age']),
            );
        }

        return $depths;
    }
}
