<?php

declare(strict_types=1);

namespace App\Module\Workflow\Repository;

use App\Module\Workflow\Entity\WorkflowQueuedEvaluation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<WorkflowQueuedEvaluation> */
class WorkflowQueuedEvaluationRepository extends ServiceEntityRepository
{
    /** A row older than this no longer blocks a trigger. It exceeds a normal wait in the queue and stays under the Doctrine transport redelivery time of 3600 s. */
    public const string STALE_AFTER = '10 minutes';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowQueuedEvaluation::class);
    }

    /**
     * Answers whether the caller must queue a message: no fresh row exists, so the claim inserted one or took over a stale one.
     * One statement, so it joins a caller's transaction. A claim that finds a fresh row changes nothing but still locks it
     * until that transaction ends, so a release waits for the caller to commit.
     */
    public function claim(Uuid $cardId, \DateTimeImmutable $now): bool
    {
        return false !== $this->getEntityManager()->getConnection()->fetchOne(
            'INSERT INTO workflow_queued_evaluations (card_id, queued_at)
             VALUES (:card, :now)
             ON CONFLICT (card_id) DO UPDATE SET queued_at = EXCLUDED.queued_at
             WHERE workflow_queued_evaluations.queued_at < :staleBefore
             RETURNING card_id',
            [
                'card' => $cardId->toRfc4122(),
                'now' => $now->format('Y-m-d H:i:s'),
                'staleBefore' => $now->modify('-'.self::STALE_AFTER)->format('Y-m-d H:i:s'),
            ],
        );
    }

    /** One statement, so it joins a caller's transaction. */
    public function release(Uuid $cardId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM workflow_queued_evaluations WHERE card_id = :card',
            ['card' => $cardId->toRfc4122()],
        );
    }
}
