<?php

declare(strict_types=1);

namespace App\Module\Bridge\Repository;

use App\Module\Bridge\Entity\ExperimentArchive;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExperimentArchive>
 */
class ExperimentArchiveRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExperimentArchive::class);
    }

    /** Read fresh, so a row that another request changed shows its stored values even when it is already managed. */
    public function findOneByName(Project $project, string $experiment): ?ExperimentArchive
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.project = :project')
            ->andWhere('a.experiment = :experiment')
            ->setParameter('project', $project)
            ->setParameter('experiment', $experiment)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }

    /** @return array<string, ExperimentArchive> experiment => row */
    public function findByProject(Project $project): array
    {
        $byName = [];
        foreach ($this->findBy(['project' => $project]) as $archive) {
            $byName[$archive->experiment] = $archive;
        }

        return $byName;
    }

    /** @return list<ExperimentArchive> */
    public function findAllRows(): array
    {
        return array_values($this->findAll());
    }

    /** Deletes the rows whose experiment has no run and no pin left. */
    public function deleteWithoutExperiment(): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM bridge_experiment_archives a
            WHERE NOT EXISTS (SELECT 1 FROM bridge_worker_runs r WHERE r.project_id = a.project_id AND r.experiment = a.experiment)
            AND NOT EXISTS (SELECT 1 FROM bridge_experiment_pins p WHERE p.project_id = a.project_id AND p.experiment = a.experiment)',
        );
    }
}
