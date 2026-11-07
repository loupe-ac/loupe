<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/** The admin flags that set how often a kept result is refreshed. */
final readonly class InsightsRefreshSettings
{
    public const string SWEEP_INTERVAL_FLAG = 'insights.sweep_interval_minutes';

    public const int SWEEP_INTERVAL_DEFAULT = 15;

    public function __construct(
        private FeatureFlagService $featureFlags,
    ) {
    }

    /** Minutes. A kept result younger than this is fresh enough for a share page. */
    public function sweepIntervalMinutes(): int
    {
        return max(1, $this->featureFlags->getIntValue(self::SWEEP_INTERVAL_FLAG, self::SWEEP_INTERVAL_DEFAULT));
    }
}
