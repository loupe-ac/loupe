<?php

declare(strict_types=1);

namespace App\Module\Artifact\Controller\Api;

use App\Module\Artifact\Entity\ArtifactVersion;
use OpenApi\Attributes as OA;

/** The version that a store created. */
#[OA\Schema(required: ['artifactId', 'versionNumber', 'url', 'versionUrl'])]
final readonly class ArtifactStoredResponse
{
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $artifactId,
        public int $versionNumber,

        #[OA\Property(description: 'The artifact page in the app, at the newest version.', format: 'uri')]
        public string $url,

        #[OA\Property(description: 'The page of this version in the app.', format: 'uri')]
        public string $versionUrl,
    ) {
    }

    public static function fromVersion(ArtifactVersion $version, string $url, string $versionUrl): self
    {
        return new self((string) $version->artifact->id, $version->number, $url, $versionUrl);
    }
}
