<?php

declare(strict_types=1);

namespace App\Module\Billing\Command;

use App\Module\Billing\Repository\BetaInviteRepository;
use App\Module\Billing\Service\BetaWelcomeEmailSender;

/**
 * The retry for a welcome email whose first trigger did not send it: a queue
 * write that failed, or a redeemer who verified in a way that fires no event.
 * The sender's conditional update still decides who sends, so a pass that races
 * a trigger sends one email.
 */
final readonly class SendDueBetaWelcomeEmailsHandler
{
    private const int BATCH = 100;

    public function __construct(
        private BetaInviteRepository $betaInvites,
        private BetaWelcomeEmailSender $welcomeEmail,
    ) {
    }

    /** @return int how many due invites this pass offered to the sender */
    public function __invoke(SendDueBetaWelcomeEmailsCommand $command): int
    {
        $due = $this->betaInvites->findWelcomeDue(self::BATCH);

        foreach ($due as $invite) {
            $this->welcomeEmail->sendIfDue($invite);
        }

        return \count($due);
    }
}
