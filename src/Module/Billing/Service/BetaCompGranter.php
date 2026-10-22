<?php

declare(strict_types=1);

namespace App\Module\Billing\Service;

use App\Exception\DomainErrors;
use App\Module\Billing\Command\Admin\GrantCompCommand;
use App\Module\Billing\Command\Admin\GrantCompHandler;
use App\Module\Billing\Entity\BetaInvite;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Runs after the redemption commits. It never throws: the invite is already
 * used and the account already exists, so a failed grant is logged and the
 * account keeps its trial. It returns whether the account holds the comp, so a
 * caller can tell the tester about free access only when it is true.
 */
final readonly class BetaCompGranter
{
    public function __construct(
        private GrantCompHandler $grantComp,
        private EntityManagerInterface $em,
        private Auditor $auditor,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(BetaInvite $invite): bool
    {
        $user = $invite->redeemedBy ?? throw new \LogicException('Only a redeemed beta invite grants a comp.');
        $context = ['betaInviteId' => (string) $invite->id, 'userId' => (string) $user->id];

        // The audit record must not cost the tester the comp, so it comes second.
        // A skipped grant means the account already holds a current comp.
        $granted = true;
        try {
            ($this->grantComp)(new GrantCompCommand($user, $invite->createdBy));
        } catch (DomainErrors $e) {
            $this->logger->info('billing.beta_comp_skipped', $context + ['errors' => $e->errors]);
        } catch (\Throwable $e) {
            // Auditor::record() swallows every sink failure, so a throw means the comp did not commit.
            $granted = false;
            $this->logger->warning('billing.beta_comp_failed', $context + ['error' => $e->getMessage()]);
            $this->dropWelcomeEmail($invite, $context);
        }

        $this->auditor->record(
            'billing.beta_invite_redeemed',
            AuditOutcome::Success,
            $context,
            new AuditSubject('beta_invite', (string) $invite->id),
        );

        return $granted;
    }

    /**
     * The email promises free access, so the sweep and the verification trigger
     * must not send it for an account that did not get the comp.
     *
     * @param array<string, string> $context
     */
    private function dropWelcomeEmail(BetaInvite $invite, array $context): void
    {
        $invite->welcomeEmailDueAt = null;

        try {
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->warning('billing.beta_welcome_drop_failed', $context + ['error' => $e->getMessage()]);
        }
    }
}
