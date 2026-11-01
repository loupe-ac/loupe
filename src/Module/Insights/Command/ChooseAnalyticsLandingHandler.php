<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Insights\Repository\InsightsSavedGraphRepository;

/** Picks the tab the Analytics link opens: the Dashboard when the project shows a graph there, else Metrics. */
final readonly class ChooseAnalyticsLandingHandler
{
    public const string DASHBOARD = 'app_project_analytics_dashboard';

    public const string METRICS = 'app_project_analytics_metrics';

    public function __construct(
        private InsightsSavedGraphRepository $insightsSavedGraphs,
    ) {
    }

    /** @return self::DASHBOARD|self::METRICS the name of the route */
    public function __invoke(ChooseAnalyticsLandingCommand $command): string
    {
        return $this->insightsSavedGraphs->countActive($command->project) > 0 ? self::DASHBOARD : self::METRICS;
    }
}
