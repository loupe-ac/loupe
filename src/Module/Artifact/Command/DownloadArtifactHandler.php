<?php

declare(strict_types=1);

namespace App\Module\Artifact\Command;

use App\Module\Artifact\Repository\ArtifactVersionRepository;
use Symfony\Component\String\Slugger\SluggerInterface;

final readonly class DownloadArtifactHandler
{
    public function __construct(
        private ArtifactVersionRepository $artifactVersions,
        private SluggerInterface $slugger,
    ) {
    }

    /** Null when the artifact has no version with the asked number. */
    public function __invoke(DownloadArtifactCommand $command): ?ArtifactDownload
    {
        $artifact = $command->artifact;
        $version = null === $command->versionNumber
            ? $this->artifactVersions->findLatest($artifact)
            : $this->artifactVersions->findByNumber($artifact, $command->versionNumber);
        if (null === $version) {
            return null;
        }

        $path = tempnam(sys_get_temp_dir(), 'artifact-');
        if (false === $path) {
            throw new \RuntimeException('A temporary file for the download could not be made.');
        }

        $zip = new \ZipArchive();
        if (true !== $zip->open($path, \ZipArchive::OVERWRITE)) {
            throw new \RuntimeException('The download archive could not be opened.');
        }
        foreach ($version->files as $file) {
            $zip->addFromString($file->path, $file->blob->contents());
        }
        $zip->close();

        $slug = $this->slugger->slug($artifact->name)->lower()->toString();

        return new ArtifactDownload($path, \sprintf('%s-v%d.zip', '' === $slug ? 'artifact' : $slug, $version->number));
    }
}
