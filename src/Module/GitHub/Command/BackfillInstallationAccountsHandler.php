<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Module\GitHub\Repository\GitHubInstallationRepository;
use App\Module\GitHub\Service\GitHubAppApi;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Lists the installations of the App once and fills the GitHub account id of
 * each known record that lacks one. An installation that GitHub no longer
 * lists keeps a null id, so its rows never reconnect on their own.
 */
final readonly class BackfillInstallationAccountsHandler
{
    public function __construct(
        private GitHubInstallationRepository $gitHubInstallations,
        private GitHubAppApi $appApi,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(BackfillInstallationAccountsCommand $command): BackfilledInstallationAccounts
    {
        $missing = $this->gitHubInstallations->findWithoutAccountId();
        if ([] === $missing) {
            return new BackfilledInstallationAccounts(0, 0);
        }

        $accountIds = [];
        foreach ($this->appApi->installations() as $listed) {
            if (null !== $listed->accountId) {
                $accountIds[$listed->id] = $listed->accountId;
            }
        }

        $filled = 0;
        foreach ($missing as $installation) {
            $accountId = $accountIds[$installation->installationId] ?? null;
            if (null !== $accountId) {
                $installation->accountId = $accountId;
                ++$filled;
            }
        }
        $this->em->flush();

        $this->logger->info('github.installation_accounts_backfilled', ['filled' => $filled, 'unlisted' => \count($missing) - $filled]);

        return new BackfilledInstallationAccounts($filled, \count($missing) - $filled);
    }
}
