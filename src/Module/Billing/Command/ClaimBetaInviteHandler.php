<?php

declare(strict_types=1);

namespace App\Module\Billing\Command;

use App\Module\Billing\Entity\BetaInvite;
use App\Module\Billing\Event\BillingStateChanged;
use App\Module\Billing\Repository\BetaInviteRepository;
use App\Module\Billing\Service\BetaCompGranter;
use App\Module\Billing\Service\BetaWelcomeEmailSender;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

final readonly class ClaimBetaInviteHandler
{
    public function __construct(
        private BetaInviteRepository $betaInvites,
        private BetaCompGranter $grantBetaComp,
        private BetaWelcomeEmailSender $welcomeEmail,
        private EntityManagerInterface $em,
        private EventDispatcherInterface $events,
    ) {
    }

    /**
     * Does nothing for an unknown, used or revoked token, and nothing more for
     * a token this user already redeemed. The caller shows the outcome by
     * reading the invite again.
     */
    public function __invoke(ClaimBetaInviteCommand $command): void
    {
        $user = $command->user;

        $invite = $this->em->wrapInTransaction(function () use ($command, $user): ?BetaInvite {
            $invite = $this->betaInvites->findOneByToken($command->token);
            if (null === $invite) {
                return null;
            }

            // The lock serializes two people who hold the same link.
            $this->em->lock($invite, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($invite);

            if (!$invite->isUsable()) {
                return null;
            }

            $invite->redeem($user);

            return $invite;
        });

        if (null !== $invite) {
            $this->events->dispatch(new BillingStateChanged($user->id ?? throw new \LogicException('a persisted user always has an id')));
        }

        // After the commit, so a failed grant leaves the invite used and the
        // account on its trial, the same as at sign-up. It sends no welcome email then,
        // because the email promises free access.
        if (null !== $invite && ($this->grantBetaComp)($invite)) {
            $this->welcomeEmail->sendIfDue($invite);
        }
    }
}
