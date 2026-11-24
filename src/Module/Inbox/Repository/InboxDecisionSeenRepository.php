<?php

declare(strict_types=1);

namespace App\Module\Inbox\Repository;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Inbox\Entity\InboxDecisionSeen;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<InboxDecisionSeen> */
class InboxDecisionSeenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InboxDecisionSeen::class);
    }

    /** Moves the time the person has seen the decisions of the card forward, never back, and keeps one row for the pair. */
    public function markSeen(Project $project, User $user, Card $card, \DateTimeImmutable $at): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO inbox_decision_seen (id, project_id, user_id, card_id, seen_at) VALUES (:id, :project, :user, :card, :at)
             ON CONFLICT (user_id, card_id) DO UPDATE SET seen_at = GREATEST(inbox_decision_seen.seen_at, EXCLUDED.seen_at)',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'project' => (string) $project->id,
                'user' => (string) $user->id,
                'card' => (string) $card->id,
                'at' => $at->format('Y-m-d H:i:s.u'),
            ],
        );
    }

    /**
     * Every seen row of a person, oldest first.
     *
     * @return list<InboxDecisionSeen>
     */
    public function findByUser(User $user): array
    {
        return array_values($this->createQueryBuilder('seen')
            ->andWhere('seen.user = :user')
            ->setParameter('user', $user)
            ->orderBy('seen.seenAt', 'ASC')
            ->addOrderBy('seen.id', 'ASC')
            ->getQuery()
            ->getResult());
    }
}
