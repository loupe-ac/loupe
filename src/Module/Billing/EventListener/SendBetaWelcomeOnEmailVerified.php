<?php

declare(strict_types=1);

namespace App\Module\Billing\EventListener;

use App\Module\Account\Event\UserEmailVerified;
use App\Module\Billing\Repository\BetaInviteRepository;
use App\Module\Billing\Service\BetaWelcomeEmailSender;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Offers the welcome email for every redeemed invite still due once the address
 * is verified. A tester who redeemed before verifying gets it here, not at redemption.
 */
#[AsEventListener]
final readonly class SendBetaWelcomeOnEmailVerified
{
    public function __construct(
        private BetaInviteRepository $betaInvites,
        private BetaWelcomeEmailSender $welcomeEmail,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(UserEmailVerified $event): void
    {
        // The address is verified and flushed, so a failure here must not fail the request.
        try {
            foreach ($this->betaInvites->findWelcomeDueFor($event->user) as $invite) {
                $this->welcomeEmail->sendIfDue($invite);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('billing.beta_welcome_email_failed', [
                'userId' => (string) $event->user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
