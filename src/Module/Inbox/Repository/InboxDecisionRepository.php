<?php

declare(strict_types=1);

namespace App\Module\Inbox\Repository;

use App\Module\Board\Entity\Card;
use App\Module\Inbox\Entity\InboxDecision;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<InboxDecision> */
class InboxDecisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InboxDecision::class);
    }

    /** The decision with this id inside this project, so a URL cannot reach another project's decision. */
    public function findOneByIdAndProjectId(string $decisionId, string $projectId): ?InboxDecision
    {
        if (!Uuid::isValid($decisionId) || !Uuid::isValid($projectId)) {
            return null;
        }

        return $this->createQueryBuilder('decision')
            ->andWhere('decision.id = :decisionId')
            ->andWhere('decision.project = :projectId')
            ->setParameter('decisionId', Uuid::fromString($decisionId), UuidType::NAME)
            ->setParameter('projectId', Uuid::fromString($projectId), UuidType::NAME)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Every decision of a card, newest first.
     *
     * @return list<InboxDecision>
     */
    public function findOfCard(Card $card): array
    {
        return array_values($this->createQueryBuilder('decision')
            ->andWhere('decision.card = :card')
            ->setParameter('card', $card->id, UuidType::NAME)
            ->orderBy('decision.createdAt', 'DESC')
            ->addOrderBy('decision.id', 'DESC')
            ->getQuery()
            ->getResult());
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
     * The stored change time and address time of each decision, read from the database and never from the identity map.
     *
     * @param list<Uuid> $ids
     *
     * @return array<string, array{changedAt: ?\DateTimeImmutable, addressedAt: ?\DateTimeImmutable}>
     */
    public function readChangeState(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        $rows = $this->createQueryBuilder('decision')
            ->select('decision.id AS id', 'decision.changedAt AS changedAt', 'decision.addressedAt AS addressedAt')
            ->andWhere('decision.id IN (:ids)')
            ->setParameter('ids', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $ids), ArrayParameterType::STRING)
            ->getQuery()
            ->getResult();
        $state = [];
        foreach ($rows as $row) {
            $state[(string) $row['id']] = ['changedAt' => $row['changedAt'], 'addressedAt' => $row['addressedAt']];
        }

        return $state;
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
