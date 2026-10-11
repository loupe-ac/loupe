<?php

declare(strict_types=1);

namespace App\Module\Artifact\Repository;

use App\Module\Account\Entity\User;
use App\Module\Artifact\Entity\Artifact;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<Artifact> */
class ArtifactRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Artifact::class);
    }

    /**
     * Archived artifacts are left out unless asked for.
     *
     * @return list<Artifact>
     */
    public function findByProject(Project $project, bool $includeArchived = false): array
    {
        $builder = $this->createQueryBuilder('a')
            ->andWhere('a.project = :project')
            ->setParameter('project', $project)
            ->orderBy('a.createdAt', 'DESC');

        if (!$includeArchived) {
            $builder->andWhere('a.archivedAt IS NULL');
        }

        return array_values($builder->getQuery()->getResult());
    }

    /**
     * Every artifact in the projects the user owns, archived ones included.
     *
     * @return list<Artifact>
     */
    public function findByProjectOwner(User $owner): array
    {
        return array_values($this->createQueryBuilder('a')
            ->join('a.project', 'p')
            ->andWhere('p.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('a.createdAt', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /**
     * Archived artifacts are not counted.
     *
     * @param list<Project> $projects
     *
     * @return array<string, int> keyed by project id; a project with no artifact is absent
     */
    public function countByProjects(array $projects): array
    {
        if ([] === $projects) {
            return [];
        }

        /** @var list<array{id: mixed, total: mixed}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('IDENTITY(a.project) AS id, COUNT(a.id) AS total')
            ->andWhere('a.project IN (:projects)')
            ->andWhere('a.archivedAt IS NULL')
            ->setParameter('projects', $projects)
            ->groupBy('a.project')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['id']] = (int) $row['total'];
        }

        return $counts;
    }

    /** Scoped to the project, so an id cannot reach the artifact of another project. */
    public function findOneByIdInProject(string $id, Project $project): ?Artifact
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->findOneBy(['id' => Uuid::fromString($id), 'project' => $project]);
    }

    /**
     * One page of the project's artifacts, newest first. Archived ones are left out unless asked for.
     *
     * @return list<Artifact>
     */
    public function findPageByProject(Project $project, bool $includeArchived, int $limit, int $offset): array
    {
        $builder = $this->createQueryBuilder('a')
            ->andWhere('a.project = :project')
            ->setParameter('project', $project)
            ->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if (!$includeArchived) {
            $builder->andWhere('a.archivedAt IS NULL');
        }

        return array_values($builder->getQuery()->getResult());
    }

    public function countInProject(Project $project, bool $includeArchived): int
    {
        $builder = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.project = :project')
            ->setParameter('project', $project);

        if (!$includeArchived) {
            $builder->andWhere('a.archivedAt IS NULL');
        }

        return (int) $builder->getQuery()->getSingleScalarResult();
    }

    /** Reads the stored archive time past the loaded entity, which a row lock does not refresh. */
    public function archivedAtOf(Artifact $artifact): ?\DateTimeImmutable
    {
        /** @var array{archivedAt: ?\DateTimeImmutable} $state */
        $state = $this->createQueryBuilder('a')
            ->select('a.archivedAt')
            ->andWhere('a = :artifact')
            ->setParameter('artifact', $artifact)
            ->getQuery()
            ->getSingleResult();

        return $state['archivedAt'];
    }

    public function clearArchivedAt(Artifact $artifact): void
    {
        $this->getEntityManager()
            ->createQuery('UPDATE '.Artifact::class.' a SET a.archivedAt = NULL WHERE a = :artifact')
            ->setParameter('artifact', $artifact)
            ->execute();
    }
}
