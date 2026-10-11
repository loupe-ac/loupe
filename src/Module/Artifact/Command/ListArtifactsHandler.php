<?php

declare(strict_types=1);

namespace App\Module\Artifact\Command;

use App\Module\Artifact\Repository\ArtifactRepository;
use App\Module\Artifact\Repository\ArtifactVersionRepository;
use App\Module\Artifact\Service\ArtifactLinkedCardsInterface;

final readonly class ListArtifactsHandler
{
    public function __construct(
        private ArtifactRepository $artifacts,
        private ArtifactVersionRepository $artifactVersions,
        private ArtifactLinkedCardsInterface $linkedCards,
    ) {
    }

    public function __invoke(ListArtifactsCommand $command): ListArtifactsView
    {
        $artifacts = $this->artifacts->findByProject($command->project, $command->includeArchived);
        $latest = $this->artifactVersions->findLatestForArtifacts($artifacts);
        $counts = $this->linkedCards->countFor($artifacts);

        $items = [];
        foreach ($artifacts as $artifact) {
            $key = (string) $artifact->id;
            // A stored artifact always has a first version; one with none cannot be shown.
            if (!isset($latest[$key])) {
                continue;
            }
            $items[] = new ArtifactListItem($artifact, $latest[$key], $counts[$key] ?? 0);
        }

        // Newest activity first, so a fresh version lifts its artifact to the top.
        usort($items, static fn (ArtifactListItem $a, ArtifactListItem $b): int => $b->latest->createdAt <=> $a->latest->createdAt);

        return new ListArtifactsView($items, $command->includeArchived);
    }
}
