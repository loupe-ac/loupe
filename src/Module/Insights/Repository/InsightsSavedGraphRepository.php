<?php

declare(strict_types=1);

namespace App\Module\Insights\Repository;

use App\Module\Account\Entity\User;
use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
}
