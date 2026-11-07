<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Module\Insights\Repository\InsightsKeptResultRepository;
use App\Module\Insights\View\PublicExperimentResult;
use App\Module\Project\Entity\Project;
use Psr\Clock\ClockInterface;

/**
 * The report of a shared experiment. It reads the result that the worker keeps. A result younger than the sweep
 * interval is used as it is. Otherwise the statistics run in the request of the visitor, and the result is kept when
 * no worker holds the compute lock. The kept result holds the totals of the report and none of its cards, so a visitor
 * never gets a card.
 */
final readonly class PublicExperimentReport
{
    public function __construct(
        private InsightsKeptResultRepository $insightsKeptResults,
        private InsightsResultKeeper $keeper,
        private InsightsRefreshSettings $settings,
        private ClockInterface $clock,
    ) {
    }

    /** @return PublicExperimentResult|null null when the project has no such experiment */
    public function forExperiment(Project $project, string $experiment): ?PublicExperimentResult
    {
        $result = $this->insightsKeptResults->findForExperiment($project, $experiment)?->result();
        if ($result instanceof PublicExperimentResult && $result->computedAt > $this->clock->now()->modify('-'.$this->settings->sweepIntervalMinutes().' minutes')) {
            return $result;
        }

        return $this->keeper->experiment($project, $experiment, false);
    }
}
