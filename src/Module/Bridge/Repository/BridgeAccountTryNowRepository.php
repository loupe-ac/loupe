<?php

declare(strict_types=1);

namespace App\Module\Bridge\Repository;

use App\Doctrine\Type\MicrosecondDateTimeImmutableType;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\BridgeAccountTryNow;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<BridgeAccountTryNow>
 */
class BridgeAccountTryNowRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BridgeAccountTryNow::class);
    }

    /**
     * The overrides of the bridge that hold now.
     *
     * @return list<BridgeAccountTryNow>
     */
    public function findOpenOfBridge(Bridge $bridge, \DateTimeImmutable $now): array
    {
        return array_values($this->createQueryBuilder('t')
            ->join('t.bridge', 'b')
            ->andWhere('b.id = :bridgeId')
            ->andWhere('b.owner = :owner')
            ->andWhere('t.closedAt IS NULL')
            ->andWhere('t.pressedAt > :since')
            ->setParameter('bridgeId', $bridge->id, UuidType::NAME)
            ->setParameter('owner', $bridge->owner)
            ->setParameter('since', $now->modify(\sprintf('-%d minutes', BridgeAccountTryNow::LIFETIME_MINUTES)), MicrosecondDateTimeImmutableType::NAME)
            ->getQuery()
            ->getResult());
    }

    /** The row that the unique index counts as open. It can be past its minutes and still unclosed. */
    public function findUnclosed(Bridge $bridge, string $account): ?BridgeAccountTryNow
    {
        return $this->createQueryBuilder('t')
            ->join('t.bridge', 'b')
            ->andWhere('b.id = :bridgeId')
            ->andWhere('b.owner = :owner')
            ->andWhere('t.account = :account')
            ->andWhere('t.closedAt IS NULL')
            ->setParameter('bridgeId', $bridge->id, UuidType::NAME)
            ->setParameter('owner', $bridge->owner)
            ->setParameter('account', $account)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Whether the bridge of the owner holds an open override for its account of that name. */
    public function hasOpenForBridgeAccount(Uuid $ownerId, Uuid $bridgeId, string $account, \DateTimeImmutable $now): bool
    {
        return null !== $this->createQueryBuilder('t')
            ->select('1')
            ->join('t.bridge', 'b')
            ->andWhere('IDENTITY(b.owner) = :owner')
            ->andWhere('b.id = :bridge')
            ->andWhere('t.account = :account')
            ->andWhere('t.closedAt IS NULL')
            ->andWhere('t.pressedAt > :since')
            ->setParameter('owner', $ownerId, 'uuid')
            ->setParameter('bridge', $bridgeId, 'uuid')
            ->setParameter('account', $account)
            ->setParameter('since', $now->modify(\sprintf('-%d minutes', BridgeAccountTryNow::LIFETIME_MINUTES)), MicrosecondDateTimeImmutableType::NAME)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The newest override of each account of the bridge.
     *
     * @return array<string, BridgeAccountTryNow> by account name
     */
    public function findLatestOfBridge(Bridge $bridge): array
    {
        /** @var list<BridgeAccountTryNow> $rows */
        $rows = $this->createQueryBuilder('t')
            ->join('t.bridge', 'b')
            ->andWhere('b.id = :bridgeId')
            ->andWhere('b.owner = :owner')
            ->setParameter('bridgeId', $bridge->id, UuidType::NAME)
            ->setParameter('owner', $bridge->owner)
            ->orderBy('t.pressedAt', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->getQuery()
            ->getResult();

        // The rows are few, because the purge removes them after the retention window.
        $latest = [];
        foreach ($rows as $row) {
            $latest[$row->account] = $row;
        }

        return $latest;
    }

    /**
     * Every override of the owner, for the account data export.
     *
     * @return list<BridgeAccountTryNow>
     */
    public function findByOwner(Uuid $ownerId): array
    {
        return array_values($this->createQueryBuilder('t')
            ->join('t.bridge', 'b')
            ->andWhere('IDENTITY(b.owner) = :owner')
            ->setParameter('owner', $ownerId, 'uuid')
            ->orderBy('t.pressedAt', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /**
     * Deletes the overrides pressed before the cutoff. One lasts 15 minutes at most,
     * so the retention window of the worker runs is long past its end.
     */
    public function deletePressedBefore(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->createQueryBuilder('t')
            ->delete()
            ->andWhere('t.pressedAt < :cutoff')
            ->setParameter('cutoff', $cutoff, MicrosecondDateTimeImmutableType::NAME)
            ->getQuery()
            ->execute();
    }
}
