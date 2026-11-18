<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Workflow\Install\WorkflowInstallFlags;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/** The admin flags of the workflow sweep. */
final readonly class WorkflowSweepSettings
{
    public function __construct(
        private FeatureFlagService $featureFlags,
    ) {
    }

    /** Hours. A full sweep starts when the last one is older than this. */
    public function fullSweepIntervalHours(): int
    {
        return max(1, $this->featureFlags->getIntValue(
            WorkflowInstallFlags::FLAG_FULL_SWEEP_INTERVAL_HOURS,
            WorkflowInstallFlags::FULL_SWEEP_INTERVAL_DEFAULT_HOURS,
        ));
    }
}
