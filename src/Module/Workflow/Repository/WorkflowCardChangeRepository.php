<?php

declare(strict_types=1);

namespace App\Module\Workflow\Repository;

use App\Module\Workflow\Entity\WorkflowCardChange;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<WorkflowCardChange> */
class WorkflowCardChangeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowCardChange::class);
    }

    /** One statement, so it joins a caller's transaction. */
    public function clear(Uuid $cardId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM workflow_card_changes WHERE card_id = :card',
            ['card' => $cardId->toRfc4122()],
        );
    }

    public function isMarked(Uuid $cardId): bool
    {
        return false !== $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT 1 FROM workflow_card_changes WHERE card_id = :card',
            ['card' => $cardId->toRfc4122()],
        );
    }
}
