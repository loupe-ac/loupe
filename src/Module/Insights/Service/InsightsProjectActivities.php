<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Module\Insights\Repository\InsightsProjectActivityRepository;
use Psr\Clock\ClockInterface;

/**
 * Tells which projects are awake. A project is awake when it got new data or a view within the dormant period. Only a
 * write of new data or a view stamps it. A refresh reads data and brings none, so the sweep never keeps a project awake.
 */
final readonly class InsightsProjectActivities
{
    public function __construct(
        private InsightsProjectActivityRepository $insightsProjectActivities,
        private InsightsRefreshRequester $requester,
        private InsightsRefreshSettings $settings,
        private ClockInterface $clock,
    ) {
    }

    /** A view of a dormant project asks for a refresh of every item first, then stamps the view. */
    public function recordView(string $projectId): void
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        if (!$this->insightsProjectActivities->isAwake($projectId, $now, $this->settings->dormantDays())) {
            $this->requester->wake($projectId);
        }
        $this->insightsProjectActivities->stampView($projectId, $now);
    }

    public function recordData(string $projectId): void
    {
        $this->insightsProjectActivities->stampData($projectId, \DateTimeImmutable::createFromInterface($this->clock->now()));
    }
}
