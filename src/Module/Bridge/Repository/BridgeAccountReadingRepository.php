<?php

declare(strict_types=1);

namespace App\Module\Bridge\Repository;

use App\Module\Bridge\Entity\BridgeAccountReading;
use App\Module\Bridge\ValueObject\AccountReadingReport;
use App\Module\Bridge\ValueObject\AccountUsageReading;
use App\Module\Bridge\ValueObject\NamedAccountReading;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<BridgeAccountReading>
 *
 * @phpstan-type ReadingRow array{bridge_id: string, account: string, window_key: string, used_percent: float|string|null, resets_at: string, limited: bool, source: string, read_at: string, seen_at: string}
 */
class BridgeAccountReadingRepository extends ServiceEntityRepository
{
    private const string COLUMNS = 'bridge_id, account, window_key, used_percent, resets_at, limited, source, read_at, seen_at';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BridgeAccountReading::class);
    }

    /**
     * The newest reading of each window of each account of one bridge. Two
     * readings of one second resolve by id, which grows with time.
     *
     * @return list<AccountReadingReport>
     */
    public function latestOfBridge(Uuid $ownerId, Uuid $bridgeId): array
    {
        /** @var list<ReadingRow> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT DISTINCT ON (account, window_key) '.self::COLUMNS.' FROM bridge_account_readings
                WHERE owner_id = :owner AND bridge_id = :bridge
                ORDER BY account, window_key, read_at DESC, id DESC',
            ['owner' => $ownerId->toRfc4122(), 'bridge' => $bridgeId->toRfc4122()],
        );

        return array_map(self::report(...), $rows);
    }

    /**
     * Moves the last seen time of the newest stored reading of one window forward, when the bridge reports the same
     * values again. The read time stays, because the history and the run deltas read it.
     *
     * @return bool whether the last seen time moved
     */
    public function markSeen(Uuid $ownerId, Uuid $bridgeId, string $account, string $window, \DateTimeImmutable $seenAt): bool
    {
        return 0 < $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE bridge_account_readings SET seen_at = :seen WHERE seen_at < :seen AND id = (
                SELECT id FROM bridge_account_readings
                WHERE owner_id = :owner AND bridge_id = :bridge AND account = :account AND window_key = :window
                ORDER BY read_at DESC, id DESC LIMIT 1
            )',
            ['seen' => $seenAt->setTimezone(new \DateTimeZone('UTC')), 'owner' => $ownerId->toRfc4122(), 'bridge' => $bridgeId->toRfc4122(), 'account' => $account, 'window' => $window],
            ['seen' => Types::DATETIME_IMMUTABLE],
        );
    }

    /**
     * The newest reading of each window of each account, on the named bridges of the owner of the project. A bridge
     * with no name has no address a rule could use, so its readings stay out.
     *
     * @return list<NamedAccountReading>
     */
    public function latestNamedOfProjectOwner(Uuid $projectId): array
    {
        /** @var list<array{name: string, account: string, window_key: string, used_percent: float|string|null, resets_at: string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT DISTINCT ON (b.name, r.account, r.window_key) b.name, r.account, r.window_key, r.used_percent, r.resets_at
                FROM bridge_account_readings r
                JOIN bridges b ON b.owner_id = r.owner_id AND b.id = r.bridge_id
                JOIN projects p ON p.owner_id = r.owner_id
                WHERE p.id = :project AND b.name IS NOT NULL
                ORDER BY b.name, r.account, r.window_key, r.read_at DESC, r.id DESC',
            ['project' => $projectId->toRfc4122()],
        );

        $utc = new \DateTimeZone('UTC');

        return array_map(static fn (array $row): NamedAccountReading => new NamedAccountReading(
            $row['name'],
            $row['account'],
            $row['window_key'],
            null === $row['used_percent'] ? null : (float) $row['used_percent'],
            new \DateTimeImmutable($row['resets_at'], $utc),
        ), $rows);
    }

    /**
     * The readings that have a use value, of the given bridges of one owner, read at or after the cutoff. A null
     * cutoff reads them all.
     *
     * @param list<Uuid> $bridgeIds
     *
     * @return list<AccountUsageReading>
     */
    public function findUsageOfBridges(Uuid $ownerId, array $bridgeIds, ?\DateTimeImmutable $since): array
    {
        if ([] === $bridgeIds) {
            return [];
        }

        $params = ['owner' => $ownerId->toRfc4122(), 'bridges' => array_map(static fn (Uuid $id): string => $id->toRfc4122(), $bridgeIds)];
        $types = ['bridges' => ArrayParameterType::STRING];
        $sql = 'SELECT id, bridge_id, account, window_key, used_percent, read_at FROM bridge_account_readings
            WHERE owner_id = :owner AND bridge_id IN (:bridges) AND used_percent IS NOT NULL';
        if (null !== $since) {
            $sql .= ' AND read_at >= :since';
            $params['since'] = $since->setTimezone(new \DateTimeZone('UTC'));
            $types['since'] = Types::DATETIME_IMMUTABLE;
        }

        /** @var list<array{id: string, bridge_id: string, account: string, window_key: string, used_percent: float|string, read_at: string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative($sql.' ORDER BY read_at, id', $params, $types);
        $utc = new \DateTimeZone('UTC');

        return array_map(static fn (array $row): AccountUsageReading => new AccountUsageReading(
            Uuid::fromString($row['id']),
            Uuid::fromString($row['bridge_id']),
            $row['account'],
            $row['window_key'],
            (float) $row['used_percent'],
            new \DateTimeImmutable($row['read_at'], $utc),
        ), $rows);
    }

    /**
     * Deletes the readings read before the cutoff, except the newest one of
     * each window of each account, so an old window still reads as Unknown
     * and not as missing.
     */
    public function deleteReadBeforeKeepingLatest(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM bridge_account_readings AS old
                WHERE old.read_at < :cutoff
                AND EXISTS (
                    SELECT 1 FROM bridge_account_readings AS newer
                    WHERE newer.owner_id = old.owner_id AND newer.bridge_id = old.bridge_id
                    AND newer.account = old.account AND newer.window_key = old.window_key
                    AND (newer.read_at, newer.id) > (old.read_at, old.id)
                )',
            ['cutoff' => $cutoff->setTimezone(new \DateTimeZone('UTC'))],
            ['cutoff' => Types::DATETIME_IMMUTABLE],
        );
    }

    /**
     * Every reading of every bridge of the owner, one row at a time, for the
     * account data export.
     *
     * @return iterable<array{bridgeId: string, reading: AccountReadingReport}>
     */
    public function iterateByOwner(Uuid $ownerId): iterable
    {
        $rows = $this->getEntityManager()->getConnection()->iterateAssociative(
            'SELECT '.self::COLUMNS.' FROM bridge_account_readings WHERE owner_id = :owner ORDER BY bridge_id, account, window_key, read_at, id',
            ['owner' => $ownerId->toRfc4122()],
        );

        /** @var ReadingRow $row */
        foreach ($rows as $row) {
            yield ['bridgeId' => $row['bridge_id'], 'reading' => self::report($row)];
        }
    }

    /** @param ReadingRow $row */
    private static function report(array $row): AccountReadingReport
    {
        $utc = new \DateTimeZone('UTC');

        return new AccountReadingReport(
            account: $row['account'],
            window: $row['window_key'],
            usedPercent: null === $row['used_percent'] ? null : (float) $row['used_percent'],
            resetsAt: new \DateTimeImmutable($row['resets_at'], $utc),
            limited: $row['limited'],
            source: $row['source'],
            readAt: new \DateTimeImmutable($row['read_at'], $utc),
            seenAt: new \DateTimeImmutable($row['seen_at'], $utc),
        );
    }
}
