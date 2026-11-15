<?php

declare(strict_types=1);

namespace App\Module\Artifact\Command;

use App\Module\Artifact\Repository\ArtifactVersionRepository;

/** Tells an open artifact page what changed since it rendered: a newer version, or a changed archive state. */
final readonly class ShowArtifactLiveBandHandler
{
    public function __construct(
        private ArtifactVersionRepository $artifactVersions,
    ) {
    }

    public function __invoke(ShowArtifactLiveBandCommand $command): ShowArtifactLiveBandView
    {
        $latest = $this->artifactVersions->findLatest($command->artifact)?->number;
        $archived = null !== $command->artifact->archivedAt;

        return new ShowArtifactLiveBandView(
            null !== $latest && $latest > $command->shownVersion ? $latest : null,
            $archived && !$command->shownArchived,
            !$archived && $command->shownArchived,
        );
    }
}
