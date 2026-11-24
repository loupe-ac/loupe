<?php

declare(strict_types=1);

namespace App\Module\Inbox\Repository;

use App\Module\Inbox\Entity\InboxDecision;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<InboxDecision> */
class InboxDecisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InboxDecision::class);
    }

    /**
     * The changes of a card that no worker acted on yet, oldest first.
     *
     * @return list<InboxDecision>
     */
    public function findOpenChangesOfCard(Project $project, Uuid $cardId): array
    {
        return array_values($this->createQueryBuilder('decision')
            ->andWhere('decision.project = :project')
            ->andWhere('decision.card = :card')
            ->andWhere('decision.changedAt IS NOT NULL')
            ->andWhere('decision.addressedAt IS NULL')
            ->setParameter('project', $project)
            ->setParameter('card', $cardId, 'uuid')
            ->orderBy('decision.changedAt', 'ASC')
            ->addOrderBy('decision.id', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /** True while the card holds a change that no worker acted on yet. */
    public function hasOpenChange(Uuid $projectId, Uuid $cardId): bool
    {
        return false !== $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT 1 FROM inbox_decisions WHERE project_id = :project AND card_id = :card AND changed_at IS NOT NULL AND addressed_at IS NULL LIMIT 1',
            ['project' => $projectId->toRfc4122(), 'card' => $cardId->toRfc4122()],
        );
    }

    /**
     * The decisions of a project with these ids. An id of another project, or none, gives no row.
     *
     * @param list<Uuid> $ids
     *
     * @return list<InboxDecision>
     */
    public function findByIdsInProject(Project $project, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        return array_values($this->createQueryBuilder('decision')
            ->andWhere('decision.project = :project')
            ->andWhere('decision.id IN (:ids)')
            ->setParameter('project', $project)
            ->setParameter('ids', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $ids), ArrayParameterType::STRING)
            ->getQuery()
            ->getResult());
    }
}
