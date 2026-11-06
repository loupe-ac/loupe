<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Module\Insights\Entity\PublicLink;
use App\Module\Project\Entity\Project;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/** Names the reason that stops a project from sharing an item, or says nothing blocks it. Every share action asks it. */
final readonly class PublicShareGuard
{
    public function __construct(
        private FeatureFlagService $featureFlags,
    ) {
    }

    public function blockedBy(Project $project): ?PublicShareBlock
    {
        if (!$this->featureFlags->isEnabled(PublicLink::FLAG, true)) {
            return PublicShareBlock::FlagOff;
        }

        return null;
    }
}
