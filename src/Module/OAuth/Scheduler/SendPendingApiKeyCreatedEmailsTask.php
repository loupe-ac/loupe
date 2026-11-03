<?php

declare(strict_types=1);

namespace App\Module\OAuth\Scheduler;

use App\Module\OAuth\Command\SendPendingApiKeyCreatedEmailsCommand;
use App\Module\OAuth\Command\SendPendingApiKeyCreatedEmailsHandler;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

#[AsCronTask('41 * * * *')]
final readonly class SendPendingApiKeyCreatedEmailsTask
{
    public function __construct(
        private SendPendingApiKeyCreatedEmailsHandler $sendEmails,
    ) {
    }

    public function __invoke(): void
    {
        ($this->sendEmails)(new SendPendingApiKeyCreatedEmailsCommand());
    }
}
