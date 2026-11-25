<?php

declare(strict_types=1);

namespace App\Module\Readiness\Scheduler;

use App\Module\Readiness\Command\AdoptLegacyDiscoveryRunsCommand;
use App\Module\Readiness\Command\AdoptLegacyDiscoveryRunsHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/**
 * Gives the discovery runs that still hold a card their work about the run, every minute.
 * `app:adopt-legacy-discovery-runs` is the manual backstop.
 */
#[AsCronTask('* * * * *')]
final readonly class AdoptLegacyDiscoveryRunsTask
{
    public function __construct(
        private AdoptLegacyDiscoveryRunsHandler $adoptLegacyDiscoveryRuns,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(): void
    {
        $changed = ($this->adoptLegacyDiscoveryRuns)(new AdoptLegacyDiscoveryRunsCommand());

        if ($changed > 0) {
            $this->logger->info('readiness.legacy_runs_adopted', ['changed' => $changed]);
        }
    }
}
