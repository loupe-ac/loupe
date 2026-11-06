<?php

declare(strict_types=1);

namespace App\Module\Bridge\Repository;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\ExperimentExclusion;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ExperimentExclusion>
 */
class ExperimentExclusionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExperimentExclusion::class);
    }

    /** @return list<ExperimentExclusion> */
    public function findOfExperiment(Project $project, string $experiment): array
    {
        return array_values($this->findBy(['project' => $project, 'experiment' => $experiment], ['excludedAt' => 'ASC', 'id' => 'ASC']));
    }

    /** @return list<ExperimentExclusion> */
    public function findByOwner(User $user): array
    {
        return array_values($this->createQueryBuilder('exclusion')
            ->join('exclusion.project', 'p')
            ->andWhere('p.owner = :user')
            ->setParameter('user', $user)
            ->orderBy('exclusion.excludedAt', 'ASC')
            ->addOrderBy('exclusion.id', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /** One statement, so two parallel calls on the same card never collide. */
    public function upsert(Project $project, string $experiment, Uuid $cardId, string $reason, ?User $author, \DateTimeImmutable $at): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO bridge_experiment_exclusions (id, project_id, card_id, experiment, reason, excluded_by_id, excluded_at)'
            .' VALUES (:id, :project, :card, :experiment, :reason, :author, :at)'
            .' ON CONFLICT (project_id, experiment, card_id) DO UPDATE SET reason = EXCLUDED.reason, excluded_by_id = EXCLUDED.excluded_by_id, excluded_at = EXCLUDED.excluded_at',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'project' => ($project->id ?? throw new \LogicException('A persisted project has an id.'))->toRfc4122(),
                'card' => $cardId->toRfc4122(),
                'experiment' => $experiment,
                'reason' => $reason,
                'author' => $author?->id?->toRfc4122(),
                'at' => $at->format('Y-m-d H:i:s'),
            ],
        );
    }

    public function deleteOfCard(Project $project, string $experiment, Uuid $cardId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM bridge_experiment_exclusions WHERE project_id = :project AND experiment = :experiment AND card_id = :card',
            [
                'project' => ($project->id ?? throw new \LogicException('A persisted project has an id.'))->toRfc4122(),
                'experiment' => $experiment,
                'card' => $cardId->toRfc4122(),
            ],
        );
    }
}
