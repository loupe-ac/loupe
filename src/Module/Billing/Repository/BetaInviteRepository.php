<?php

declare(strict_types=1);

namespace App\Module\Billing\Repository;

use App\Module\Account\Entity\User;
use App\Module\Billing\Entity\BetaInvite;
use App\Module\Billing\Entity\Subscription;
use App\Module\Billing\Entity\SubscriptionKind;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * @extends ServiceEntityRepository<BetaInvite>
 */
class BetaInviteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BetaInvite::class);
    }

    /** Any state: the caller decides whether a used or revoked invite counts. */
    public function findOneByToken(string $token): ?BetaInvite
    {
        return $this->findOneBy(['token' => $token]);
    }

    /** The first redemption, when a user redeemed several links. */
    public function findOneRedeemedBy(User $user): ?BetaInvite
    {
        return $this->findOneBy(['redeemedBy' => $user], ['redeemedAt' => 'ASC']);
    }

    /** @return list<BetaInvite> */
    public function findAllNewestFirst(): array
    {
        return $this->createQueryBuilder('i')
            ->leftJoin('i.redeemedBy', 'r')
            ->addSelect('r')
            ->orderBy('i.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Clears the due date of one invite and tells whether this call did it.
     * One conditional UPDATE decides which of two racing callers sends the
     * email. It runs on the ORM connection, so a caller's transaction holds it.
     */
    public function claimWelcomeEmail(BetaInvite $invite): bool
    {
        return 1 === $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE beta_invites SET welcome_email_due_at = NULL WHERE id = :id AND welcome_email_due_at IS NOT NULL',
            ['id' => $invite->id],
            ['id' => UuidType::NAME],
        );
    }

    /**
     * The sweep's batch: invites that still wait and whose redeemer has a
     * verified address and a current comp, oldest redemption first. An invite
     * the sender would withhold must not take a place in the batch.
     *
     * @return list<BetaInvite>
     */
    public function findWelcomeDue(int $limit): array
    {
        $comp = $this->getEntityManager()->createQueryBuilder()
            ->select('1')
            ->from(Subscription::class, 's')
            ->join('s.billingProfile', 'p')
            ->where('p.user = r')
            ->andWhere('s.kind = :comp')
            ->andWhere('s.startsAt <= :now AND (s.endsAt IS NULL OR s.endsAt > :now)');

        return $this->createQueryBuilder('i')
            ->innerJoin('i.redeemedBy', 'r')
            ->addSelect('r')
            ->where('i.welcomeEmailDueAt IS NOT NULL')
            ->andWhere('r.emailVerifiedAt IS NOT NULL')
            ->andWhere('EXISTS ('.$comp->getDQL().')')
            ->setParameter('comp', SubscriptionKind::Comp)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('i.redeemedAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Every invite the user redeemed that still waits, whether or not the
     * address is verified. The sender applies the verification guard.
     *
     * @return list<BetaInvite>
     */
    public function findWelcomeDueFor(User $user): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.redeemedBy = :user')
            ->andWhere('i.welcomeEmailDueAt IS NOT NULL')
            ->setParameter('user', $user->id, UuidType::NAME)
            ->orderBy('i.redeemedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
