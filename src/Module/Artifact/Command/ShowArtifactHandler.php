<?php

declare(strict_types=1);

namespace App\Module\Artifact\Command;

use App\Module\Artifact\Entity\ArtifactFile;
use App\Module\Artifact\Repository\ArtifactVersionRepository;
use App\Module\Artifact\Service\ArtifactAllowedOrigins;
use App\Module\Artifact\Service\ArtifactContentHost;
use App\Module\Artifact\Service\ArtifactContentSigner;
use App\Module\Artifact\Service\ArtifactLinkedCardsInterface;

final readonly class ShowArtifactHandler
{
    public function __construct(
        private ArtifactVersionRepository $artifactVersions,
        private ArtifactLinkedCardsInterface $linkedCards,
        private ArtifactContentSigner $signer,
        private ArtifactContentHost $contentHost,
        private ArtifactAllowedOrigins $allowedOrigins,
    ) {
    }

    /** Null when the artifact has no version with the asked number, or the version has no file with the asked path. */
    public function __invoke(ShowArtifactCommand $command): ?ShowArtifactView
    {
        $artifact = $command->artifact;
        $latest = $this->artifactVersions->findLatest($artifact);
        if (null === $latest) {
            return null;
        }

        $version = null === $command->versionNumber
            ? $latest
            : $this->artifactVersions->findByNumber($artifact, $command->versionNumber);
        if (null === $version) {
            return null;
        }

        $entry = $version->entryFile();
        $file = $entry;
        if (null !== $command->filePath) {
            $file = $version->files->findFirst(static fn (int|string $key, ArtifactFile $candidate): bool => $candidate->path === $command->filePath);
            if (null === $file) {
                return null;
            }
        }

        $pages = array_values(array_filter(
            $version->files->toArray(),
            static fn (ArtifactFile $candidate): bool => 1 === preg_match('/\.html?$/i', $candidate->path),
        ));
        usort($pages, static fn (ArtifactFile $a, ArtifactFile $b): int => strcmp($a->path, $b->path));

        return new ShowArtifactView(
            $artifact,
            $version,
            array_reverse(array_values($artifact->versions->toArray())),
            $this->linkedCards->cardsOf($artifact),
            $pages,
            $file,
            $file === $entry,
            null === $file ? null : $this->signer->linkFor($version, $file->path),
            null !== $this->contentHost->host,
            $this->allowedOrigins->origins(),
        );
    }
}
