<?php

declare(strict_types=1);

namespace App\Module\Artifact\Command;

use App\Module\Artifact\Entity\Artifact;

/** Archives or restores an artifact. A second write of the same state changes nothing. */
final readonly class SetArtifactArchivedHandler
{
    public function __construct(
        private ArchiveArtifactHandler $archive,
        private UnarchiveArtifactHandler $unarchive,
    ) {
    }

    public function __invoke(SetArtifactArchivedCommand $command): Artifact
    {
        return $command->archived
            ? ($this->archive)(new ArchiveArtifactCommand($command->artifact))
            : ($this->unarchive)(new UnarchiveArtifactCommand($command->artifact));
    }
}
