<?php

declare(strict_types=1);

namespace App\Module\Billing\Scheduler;

use App\Module\Billing\Command\SendDueBetaWelcomeEmailsCommand;
use App\Module\Billing\Command\SendDueBetaWelcomeEmailsHandler;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/** `app:billing:send-due-beta-welcome-emails` is the manual backstop. */
#[AsCronTask('*/15 * * * *')]
final readonly class SendDueBetaWelcomeEmailsTask
{
    public function __construct(
        private SendDueBetaWelcomeEmailsHandler $sendDue,
    ) {
    }

    public function __invoke(): void
    {
        ($this->sendDue)(new SendDueBetaWelcomeEmailsCommand());
    }
}
