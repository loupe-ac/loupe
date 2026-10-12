<?php

declare(strict_types=1);

namespace App\Module\Artifact\Command;

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

    /** Null when the artifact has no version with the asked number. */
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

        return new ShowArtifactView(
            $artifact,
            $version,
            $latest->number,
            array_reverse(array_values($artifact->versions->toArray())),
            $this->linkedCards->cardsOf($artifact),
            null === $entry ? null : $this->signer->linkFor($version, $entry->path),
            null !== $this->contentHost->host,
            $this->allowedOrigins->origins(),
        );
    }
}
