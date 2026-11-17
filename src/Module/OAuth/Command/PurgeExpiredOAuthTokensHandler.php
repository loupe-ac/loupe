<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use App\Mercure\AccountArea;
use App\Mercure\AccountChangedPublisher;
use App\Module\OAuth\Repository\GrantRepository;
use App\Module\OAuth\Repository\WorkerRunCredentialRepository;
use Psr\Log\LoggerInterface;

final readonly class PurgeExpiredOAuthTokensHandler
{
    public function __construct(
        private GrantRepository $grants,
        private WorkerRunCredentialRepository $workerRunCredentials,
        private LoggerInterface $logger,
        private AccountChangedPublisher $accountChanged,
    ) {
    }

    public function __invoke(PurgeExpiredOAuthTokensCommand $command): int
    {
        $owners = $this->grants->findUserIdsWithExpiredTokens();
        $deleted = $this->grants->deleteExpired() + $this->workerRunCredentials->deleteExpiredBeyondRetention();
        foreach ($owners as $ownerId) {
            $this->accountChanged->changed($ownerId, AccountArea::APPS);
        }
        $this->logger->info('oauth.expired_tokens_purged', ['rows' => $deleted]);

        return $deleted;
    }
}
