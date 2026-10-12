<?php

declare(strict_types=1);

namespace App\Module\Artifact\Service;

use App\Module\Artifact\Install\ArtifactInstallFlags;
use App\Module\Artifact\Repository\ArtifactRepository;
use App\Module\Project\Stats\ProjectStats;
use App\Module\Project\Stats\ProjectStatsProviderInterface;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/** Artifact's contribution to the projects list: how many artifacts each project holds. */
final readonly class ArtifactProjectStatsProvider implements ProjectStatsProviderInterface
{
    public function __construct(
        private ArtifactRepository $artifacts,
        private FeatureFlagService $featureFlags,
    ) {
    }

    #[\Override]
    public function statsFor(array $projects): array
    {
        if (!$this->featureFlags->isEnabled(ArtifactInstallFlags::FLAG_ENABLED)) {
            return [];
        }

        return array_map(
            static fn (int $count): ProjectStats => new ProjectStats(artifactCount: $count),
            $this->artifacts->countByProjects($projects),
        );
    }
}
