<?php

declare(strict_types=1);

namespace App\Module\Artifact\Command;

use App\Module\Artifact\Entity\ArtifactFile;
use App\Module\Artifact\Repository\ArtifactFileRepository;
use App\Module\Artifact\Service\ArtifactContentHost;

final readonly class ShowArtifactContentHandler
{
    public function __construct(
        private ArtifactFileRepository $artifactFiles,
        private ArtifactContentHost $contentHost,
    ) {
    }

    public function __invoke(ShowArtifactContentCommand $command): ?ArtifactFile
    {
        $file = $this->artifactFiles->findOneByVersionIdAndPath($command->versionId, $command->path);
        if (null === $file || null === $command->label) {
            return $file;
        }

        return $this->contentHost->labelFor($file->version->artifact) === $command->label ? $file : null;
    }
}
