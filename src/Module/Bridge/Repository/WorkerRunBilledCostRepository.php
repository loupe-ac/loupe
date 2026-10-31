<?php

declare(strict_types=1);

namespace App\Module\Bridge\Repository;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\WorkerRunBilledCost;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<WorkerRunBilledCost>
 */
class WorkerRunBilledCostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkerRunBilledCost::class);
    }

    /** Waits for a writer that holds the row, then reads what that writer committed. */
    public function findForUpdate(Uuid $runId): ?WorkerRunBilledCost
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.runId = :id')
            ->setParameter('id', $runId, UuidType::NAME)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }

    /**
     * @param list<Uuid> $runIds
     *
     * @return array<string, WorkerRunBilledCost> run id => row, for the runs that have one
     */
    public function findByRunIds(array $runIds): array
    {
        if ([] === $runIds) {
            return [];
        }

        /** @var list<WorkerRunBilledCost> $rows */
        $rows = $this->createQueryBuilder('b')
            ->andWhere('b.runId IN (:ids)')
            ->setParameter('ids', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $runIds))
            ->getQuery()
            ->getResult();

        $byRun = [];
        foreach ($rows as $row) {
            $byRun[$row->runId->toRfc4122()] = $row;
        }

        return $byRun;
    }

    /**
     * Every row on every project the user owns, for the account data export.
     * It includes the rows whose run the retention sweep deleted.
     *
     * @return list<WorkerRunBilledCost>
     */
    public function findByOwner(User $user): array
    {
        return array_values($this->createQueryBuilder('b')
            ->join('b.project', 'p')
            ->andWhere('p.owner = :user')
            ->setParameter('user', $user)
            ->orderBy('b.runId', 'ASC')
            ->getQuery()
            ->getResult());
    }
}
