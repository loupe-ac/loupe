<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use App\Module\OAuth\Repository\ApiKeyRepository;
use App\Module\OAuth\Service\ApiKeyEmailSender;
use Psr\Clock\ClockInterface;

/**
 * Mails the owner of each key that ends within 7 days, once. The claim commits
 * before the mail is queued, so a failed delivery never causes a second mail.
 */
final readonly class SendApiKeyExpiryNoticesHandler
{
    private const string NOTICE_WINDOW = '+7 days';

    public function __construct(
        private ApiKeyRepository $apiKeys,
        private ApiKeyEmailSender $emails,
        private ClockInterface $clock,
    ) {
    }

    /** @return int how many notices were queued */
    public function __invoke(SendApiKeyExpiryNoticesCommand $command): int
    {
        $now = $this->clock->now();
        $sent = 0;

        foreach ($this->apiKeys->findExpiringUnnotified($now, $now->modify(self::NOTICE_WINDOW)) as $key) {
            if (null === $key->id || !$this->apiKeys->claimExpiryNotice($key->id, $now)) {
                continue;
            }

            $this->emails->sendExpiring($key);
            ++$sent;
        }

        return $sent;
    }
}
