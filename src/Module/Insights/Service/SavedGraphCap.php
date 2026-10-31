<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Exception\DomainErrors;
use App\Module\Insights\Repository\InsightsSavedGraphRepository;
use App\Module\Project\Entity\Project;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/** The most saved graphs a project may keep on its dashboard. An admin-editable flag. */
final readonly class SavedGraphCap
{
    public const string FLAG = 'insights.dashboard_graph_cap';

    public const int DEFAULT = 12;

    public const string ERROR_KEY = 'insights.saved_graph.error.cap_reached';

    public function __construct(
        private InsightsSavedGraphRepository $insightsSavedGraphs,
        private FeatureFlagService $featureFlags,
    ) {
    }

    public function limit(): int
    {
        return max(0, $this->featureFlags->getIntValue(self::FLAG, self::DEFAULT));
    }

    public function isReached(Project $project): bool
    {
        return $this->insightsSavedGraphs->countActive($project) >= $this->limit();
    }

    /** @throws DomainErrors when the project holds as many active graphs as the cap allows */
    public function assertRoom(Project $project): void
    {
        if ($this->isReached($project)) {
            throw new DomainErrors(['name' => self::ERROR_KEY], ['name' => ['%limit%' => $this->limit()]]);
        }
    }
}
