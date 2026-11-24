<?php

declare(strict_types=1);

namespace App\Module\Bridge\Repository;

use App\Module\Bridge\Entity\WorkerWait;
use App\Module\Bridge\ValueObject\WorkerWaitEndReason;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<WorkerWait>
 */
class WorkerWaitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkerWait::class);
    }

    public function findOpenOfCard(Uuid $cardId): ?WorkerWait
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.cardId = :cardId')
            ->andWhere('w.endedAt IS NULL')
            ->setParameter('cardId', $cardId, UuidType::NAME)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Locks the row of the wait until the transaction ends, and says whether the stored row is still open. The stored row decides,
     * not the managed entity, which can be older. Only the first of two racing ends sees true. Call it inside a transaction.
     */
    public function lockOpen(Uuid $id): bool
    {
        return null !== $this->createQueryBuilder('w')
            ->select('w.id')
            ->andWhere('w.id = :id')
            ->andWhere('w.endedAt IS NULL')
            ->setParameter('id', $id, UuidType::NAME)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }

    /** Whether the run opened a wait, open or ended. */
    public function existsOfRun(Uuid $runId): bool
    {
        return (int) $this->createQueryBuilder('w')
            ->select('COUNT(w.id)')
            ->andWhere('w.runId = :runId')
            ->setParameter('runId', $runId, UuidType::NAME)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /** The wait of the run that is open, or that ended in a way that resumes and has no resume run yet. */
    public function findAwaitingResumeOfRun(Uuid $runId): ?WorkerWait
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.runId = :runId')
            ->andWhere('w.endedAt IS NULL OR (w.resumeRunId IS NULL AND w.endReason IN (:reasons))')
            ->setParameter('runId', $runId, UuidType::NAME)
            ->setParameter('reasons', array_map(static fn (WorkerWaitEndReason $reason): string => $reason->value, WorkerWaitEndReason::resumingReasons()))
            ->orderBy('w.openedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Whether the wait of the run ended in a way that stops the run. */
    public function endedToStopRun(Uuid $runId): bool
    {
        return (int) $this->createQueryBuilder('w')
            ->select('COUNT(w.id)')
            ->andWhere('w.runId = :runId')
            ->andWhere('w.endReason IN (:reasons)')
            ->setParameter('runId', $runId, UuidType::NAME)
            ->setParameter('reasons', array_map(static fn (WorkerWaitEndReason $reason): string => $reason->value, WorkerWaitEndReason::stoppingReasons()))
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * The waits of the session that ended in a way that resumes, and that no run resumed yet.
     *
     * @return list<WorkerWait>
     */
    public function findEndedWithoutResumeOfSession(Uuid $bridgeId, Uuid $sessionId): array
    {
        return array_values($this->createQueryBuilder('w')
            ->andWhere('w.bridgeId = :bridge')
            ->andWhere('w.sessionId = :session')
            ->andWhere('w.endedAt IS NOT NULL')
            ->andWhere('w.resumeRunId IS NULL')
            ->andWhere('w.endReason IN (:reasons)')
            ->setParameter('bridge', $bridgeId, UuidType::NAME)
            ->setParameter('session', $sessionId, UuidType::NAME)
            ->setParameter('reasons', array_map(static fn (WorkerWaitEndReason $reason): string => $reason->value, WorkerWaitEndReason::resumingReasons()))
            ->getQuery()
            ->getResult());
    }

    /** The waits of the chain of resumed runs that starts at the root run. */
    public function countOfChain(Uuid $chainRootRunId): int
    {
        return (int) $this->createQueryBuilder('w')
            ->select('COUNT(w.id)')
            ->andWhere('w.chainRootRunId = :root')
            ->setParameter('root', $chainRootRunId, UuidType::NAME)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function deleteOfProject(Project $project): int
    {
        return (int) $this->createQueryBuilder('w')
            ->delete()
            ->andWhere('w.project = :project')
            ->setParameter('project', $project)
            ->getQuery()
            ->execute();
    }

    /** The retention sweep. A wait opened before the cut belongs to a run that the sweep deletes too. */
    public function deleteOpenedBefore(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->createQueryBuilder('w')
            ->delete()
            ->andWhere('w.openedAt < :cutoff')
            ->setParameter('cutoff', $cutoff, Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->execute();
    }
}
