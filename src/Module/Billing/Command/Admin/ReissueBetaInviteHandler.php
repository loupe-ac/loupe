<?php

declare(strict_types=1);

namespace App\Module\Billing\Command\Admin;

use App\Exception\DomainErrors;
use App\Module\Billing\Entity\BetaInvite;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class ReissueBetaInviteHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    /** @throws DomainErrors when the invite is already redeemed or revoked */
    public function __invoke(ReissueBetaInviteCommand $command): CreateBetaInviteView
    {
        $old = $command->invite;

        // A DBAL transaction, not wrapInTransaction(): a DomainErrors thrown out
        // of the closure would close the shared EntityManager.
        $view = $this->em->getConnection()->transactional(function () use ($old, $command): CreateBetaInviteView {
            // Redemption locks the same row, so a reissue cannot race a sign-up.
            $this->em->lock($old, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($old);

            if ($old->isRedeemed()) {
                throw new DomainErrors(['invite' => 'billing.admin.beta_invite.error.redeemed']);
            }
            if ($old->isRevoked()) {
                throw new DomainErrors(['invite' => 'billing.admin.beta_invite.error.revoked']);
            }

            $old->revoke();
            [$invite, $token] = BetaInvite::issue($command->createdBy, $old->note);
            $this->em->persist($invite);
            $this->em->flush();

            return new CreateBetaInviteView($invite, $token);
        });

        $this->auditor->record(
            'billing.beta_invite_reissued',
            AuditOutcome::Success,
            ['betaInviteId' => (string) $view->invite->id, 'replacedBetaInviteId' => (string) $old->id],
            new AuditSubject('beta_invite', (string) $view->invite->id),
        );

        return $view;
    }
}
