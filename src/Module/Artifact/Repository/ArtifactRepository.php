<?php

declare(strict_types=1);

namespace App\Module\Artifact\Repository;

use App\Module\Artifact\Entity\Artifact;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

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
}
