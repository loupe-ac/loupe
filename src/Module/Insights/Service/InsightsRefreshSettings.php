<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/** The admin flags that set how long new data waits before a refresh, and how often a kept result is refreshed. */
final readonly class InsightsRefreshSettings
{
    public const string SWEEP_INTERVAL_FLAG = 'insights.sweep_interval_minutes';

    public const int SWEEP_INTERVAL_DEFAULT = 15;

    public const string REFRESH_DELAY_FLAG = 'insights.refresh_delay_seconds';

    public const int REFRESH_DELAY_DEFAULT = 60;

    public const string SLOW_NOTICE_FLAG = 'insights.slow_notice_seconds';

    public const int SLOW_NOTICE_DEFAULT = 30;

    public function __construct(
        private FeatureFlagService $featureFlags,
    ) {
    }

    /** Seconds. New data of one kind waits this long, so a burst of writes costs one refresh. */
    public function refreshDelaySeconds(): int
    {
        return max(0, $this->featureFlags->getIntValue(self::REFRESH_DELAY_FLAG, self::REFRESH_DELAY_DEFAULT));
    }

    /** Minutes. A kept result younger than this is fresh enough for a share page. */
    public function sweepIntervalMinutes(): int
    {
        return max(1, $this->featureFlags->getIntValue(self::SWEEP_INTERVAL_FLAG, self::SWEEP_INTERVAL_DEFAULT));
    }

    /** Seconds a waiting tile shows its spinner before it offers Retry. */
    public function slowNoticeSeconds(): int
    {
        return max(1, $this->featureFlags->getIntValue(self::SLOW_NOTICE_FLAG, self::SLOW_NOTICE_DEFAULT));
    }
}
