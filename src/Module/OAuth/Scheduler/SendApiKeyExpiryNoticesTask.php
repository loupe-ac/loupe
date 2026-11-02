<?php

declare(strict_types=1);

namespace App\Module\OAuth\Scheduler;

use App\Module\OAuth\Command\SendApiKeyExpiryNoticesCommand;
use App\Module\OAuth\Command\SendApiKeyExpiryNoticesHandler;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

#[AsCronTask('23 * * * *')]
final readonly class SendApiKeyExpiryNoticesTask
{
    public function __construct(
        private SendApiKeyExpiryNoticesHandler $sendNotices,
    ) {
    }

    public function __invoke(): void
    {
        ($this->sendNotices)(new SendApiKeyExpiryNoticesCommand());
    }
}
