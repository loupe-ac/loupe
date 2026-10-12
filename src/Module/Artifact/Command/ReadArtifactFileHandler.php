<?php

declare(strict_types=1);

namespace App\Module\Artifact\Command;

use App\Exception\DomainErrors;
use App\Module\Artifact\Entity\ArtifactFile;
use App\Module\Artifact\Repository\ArtifactVersionRepository;

final readonly class ReadArtifactFileHandler
{
    public function __construct(
        private ArtifactVersionRepository $artifactVersions,
    ) {
    }

    public function __invoke(ReadArtifactFileCommand $command): ArtifactFile
    {
        $version = null === $command->versionNumber
            ? $this->artifactVersions->findLatest($command->artifact)
            : $this->artifactVersions->findByNumber($command->artifact, $command->versionNumber);
        if (null === $version) {
            throw new DomainErrors(['versionNumber' => 'artifact.error.version_not_found']);
        }

        foreach ($version->files as $file) {
            if ($file->path === $command->path) {
                return $file;
            }
        }

        throw new DomainErrors(['path' => 'artifact.error.file_not_found']);
    }
}
