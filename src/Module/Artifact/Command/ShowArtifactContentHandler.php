<?php

declare(strict_types=1);

namespace App\Module\Artifact\Command;

use App\Module\Artifact\Entity\ArtifactFile;
use App\Module\Artifact\Repository\ArtifactFileRepository;

final readonly class ShowArtifactContentHandler
{
    public function __construct(
        private ArtifactFileRepository $artifactFiles,
    ) {
    }

    public function __invoke(ShowArtifactContentCommand $command): ?ArtifactFile
    {
        return $this->artifactFiles->findOneByVersionIdAndPath($command->versionId, $command->path);
    }
}
