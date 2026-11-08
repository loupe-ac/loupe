<?php

declare(strict_types=1);

namespace App\Module\Insights\Scheduler;

use App\Module\Insights\Repository\InsightsProjectActivityRepository;
use App\Module\Insights\Service\InsightsDataKinds;
use App\Module\Insights\Service\InsightsRefreshRequester;
use App\Module\Insights\Service\InsightsRefreshSettings;
use Psr\Clock\ClockInterface;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/**
 * Each minute, asks for a refresh of every awake project whose rolling-range graphs, template metric graphs or shared
 * experiments hold a result older than the sweep interval. A dormant project is left out.
 */
#[AsCronTask('* * * * *')]
final readonly class SweepInsightsTask
{
    public function __construct(
        private InsightsProjectActivityRepository $insightsProjectActivities,
        private InsightsDataKinds $dataKinds,
        private InsightsRefreshRequester $requester,
        private InsightsRefreshSettings $settings,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(): void
    {
        $projectIds = $this->insightsProjectActivities->findToSweep(
            \DateTimeImmutable::createFromInterface($this->clock->now()),
            $this->settings->dormantDays(),
            $this->settings->sweepIntervalMinutes(),
            $this->dataKinds->builtInMetrics(),
        );
        foreach ($projectIds as $projectId) {
            $this->requester->sweep($projectId);
        }
    }
}
