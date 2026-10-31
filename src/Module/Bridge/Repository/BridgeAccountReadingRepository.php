<?php

declare(strict_types=1);

namespace App\Module\Bridge\Repository;

use App\Module\Bridge\Entity\BridgeAccountReading;
use App\Module\Bridge\ValueObject\AccountReadingReport;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<BridgeAccountReading>
 *
 * @phpstan-type ReadingRow array{bridge_id: string, account: string, window_key: string, used_percent: float|string|null, resets_at: string, limited: bool, source: string, read_at: string}
 */
class BridgeAccountReadingRepository extends ServiceEntityRepository
{
    private const string COLUMNS = 'bridge_id, account, window_key, used_percent, resets_at, limited, source, read_at';

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
        );
    }
}
