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

    /**
     * One statement, so it joins a caller's transaction.
     *
     * @return bool whether the card held a mark
     */
    public function clear(Uuid $cardId): bool
    {
        return 0 < $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM workflow_card_changes WHERE card_id = :card',
            ['card' => $cardId->toRfc4122()],
        );
    }

    /** @return int the cards that hold a mark */
    public function countMarked(): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne('SELECT count(*) FROM workflow_card_changes');
    }

    public function isMarked(Uuid $cardId): bool
    {
        return false !== $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT 1 FROM workflow_card_changes WHERE card_id = :card',
            ['card' => $cardId->toRfc4122()],
        );
    }

    /**
     * Turns each project mark into a mark on each open bound card of that project, and deletes the project marks. One statement,
     * so a project mark that commits after it reads its snapshot stays for the next tick.
     */
    public function expandProjectMarks(): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'WITH gone AS (DELETE FROM workflow_project_changes RETURNING project_id)
             INSERT INTO workflow_card_changes (card_id, changed_at)
             SELECT c.id, now()
             FROM board_cards c
             JOIN board_columns col ON col.id = c.column_id
             JOIN workflow_bindings b ON b.project_id = c.project_id
             WHERE col.terminal = false AND c.project_id IN (SELECT project_id FROM gone)
             ON CONFLICT (card_id) DO UPDATE SET changed_at = EXCLUDED.changed_at',
        );
    }

    /** Deletes the marks of a card that is gone, in a terminal column or in an unbound project. */
    public function deleteStale(): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM workflow_card_changes m
             WHERE NOT EXISTS (
                 SELECT 1 FROM board_cards c
                 JOIN board_columns col ON col.id = c.column_id
                 JOIN workflow_bindings b ON b.project_id = c.project_id
                 WHERE c.id = m.card_id AND col.terminal = false
             )',
        );
    }

    /**
     * @param list<string> $alwaysSql SELECTs of card ids that join every result
     *
     * @return list<string> RFC 4122 ids of the marked open bound cards and of the cards of $alwaysSql, in id order
     */
    public function findSweptCardIds(array $alwaysSql = []): array
    {
        $sources = ['SELECT card_id AS id FROM workflow_card_changes', ...array_map(static fn (string $sql): string => '('.$sql.')', $alwaysSql)];

        return array_map(strval(...), $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT c.id FROM board_cards c
             JOIN board_columns col ON col.id = c.column_id
             JOIN workflow_bindings b ON b.project_id = c.project_id
             WHERE col.terminal = false AND c.id IN ('.implode(' UNION ', $sources).')
             ORDER BY c.id',
        ));
    }
}
