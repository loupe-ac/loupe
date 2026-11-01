<?php

declare(strict_types=1);

namespace App\Module\Insights\Repository;

use App\Module\Account\Entity\User;
use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<InsightsSavedGraph> */
class InsightsSavedGraphRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InsightsSavedGraph::class);
    }

    /** @return list<InsightsSavedGraph> the graphs that are not removed, in order of position */
    public function findActive(Project $project): array
    {
        return array_values($this->createQueryBuilder('g')
            ->andWhere('g.project = :project')
            ->andWhere('g.removedAt IS NULL')
            ->setParameter('project', $project)
            ->orderBy('g.position', 'ASC')
            ->addOrderBy('g.id', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /** @return list<InsightsSavedGraph> the graphs of the projects the user owns, removed ones included, by project, then in order of position */
    public function findByOwner(User $user): array
    {
        return array_values($this->createQueryBuilder('g')
            ->join('g.project', 'p')
            ->andWhere('p.owner = :user')
            ->setParameter('user', $user)
            ->orderBy('p.name', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->addOrderBy('g.position', 'ASC')
            ->addOrderBy('g.id', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /** Null for a malformed id and for a graph of another project alike. */
    public function findOneByIdAndProjectId(string $graphId, string $projectId): ?InsightsSavedGraph
    {
        if (!Uuid::isValid($graphId) || !Uuid::isValid($projectId)) {
            return null;
        }

        return $this->createQueryBuilder('g')
            ->andWhere('g.id = :graphId')
            ->andWhere('g.project = :projectId')
            ->setParameter('graphId', Uuid::fromString($graphId), UuidType::NAME)
            ->setParameter('projectId', Uuid::fromString($projectId), UuidType::NAME)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** The graphs that are not removed. */
    public function countActive(Project $project): int
    {
        return (int) $this->createQueryBuilder('g')
            ->select('COUNT(g.id)')
            ->andWhere('g.project = :project')
            ->andWhere('g.removedAt IS NULL')
            ->setParameter('project', $project)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The position and the removal time as the database holds them now, or null when the row is gone.
     *
     * @return array{position: int, removedAt: ?\DateTimeImmutable}|null
     */
    public function findStateOf(InsightsSavedGraph $graph): ?array
    {
        /** @var array{position: int, removedAt: ?\DateTimeImmutable}|null $state */
        $state = $this->createQueryBuilder('g')
            ->select('g.position AS position', 'g.removedAt AS removedAt')
            ->andWhere('g.id = :id')
            ->setParameter('id', $graph->id, UuidType::NAME)
            ->getQuery()
            ->getOneOrNullResult();

        return $state;
    }

    /** The position after the last graph of the project, a removed graph included. */
    public function nextPosition(Project $project): int
    {
        $last = $this->createQueryBuilder('g')
            ->select('MAX(g.position)')
            ->andWhere('g.project = :project')
            ->setParameter('project', $project)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $last ? 0 : (int) $last + 1;
    }

    /** Deletes the graphs of the project that were removed before the cutoff. They are past Undo. */
    public function deleteRemovedBefore(Project $project, \DateTimeImmutable $cutoff): int
    {
        return (int) $this->createQueryBuilder('g')
            ->delete()
            ->andWhere('g.project = :project')
            ->andWhere('g.removedAt IS NOT NULL')
            ->andWhere('g.removedAt < :cutoff')
            ->setParameter('project', $project)
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();
    }

    /**
     * The graphs that are removed and still inside the Undo window, newest removal first.
     *
     * @return list<InsightsSavedGraph>
     */
    public function findRestorable(Project $project, \DateTimeImmutable $now, int $limit): array
    {
        return array_values($this->restorableQuery($project, $now)
            ->orderBy('g.removedAt', 'DESC')
            ->addOrderBy('g.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult());
    }

    public function countRestorable(Project $project, \DateTimeImmutable $now): int
    {
        return (int) $this->restorableQuery($project, $now)
            ->select('COUNT(g.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function restorableQuery(Project $project, \DateTimeImmutable $now): QueryBuilder
    {
        return $this->createQueryBuilder('g')
            ->andWhere('g.project = :project')
            ->andWhere('g.removedAt IS NOT NULL')
            ->andWhere('g.removedAt >= :cutoff')
            ->setParameter('project', $project)
            ->setParameter('cutoff', $now->modify(InsightsSavedGraph::UNDO_WINDOW));
    }
}
