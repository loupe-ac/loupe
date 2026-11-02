<?php

declare(strict_types=1);

namespace App\Module\Artifact\Controller\Api;

use App\Module\Artifact\Entity\ArtifactVersion;
use OpenApi\Attributes as OA;

/** The version that a store created. */
#[OA\Schema(required: ['artifactId', 'versionNumber', 'path', 'versionPath'])]
final readonly class ArtifactStoredResponse
{
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $artifactId,
        public int $versionNumber,

        #[OA\Property(description: 'The path of the artifact page in the app.')]
        public string $path,

        #[OA\Property(description: 'The path of this version in the app.')]
        public string $versionPath,
    ) {
    }

    public static function fromVersion(ArtifactVersion $version): self
    {
        $stable = \sprintf('/projects/%s/artifacts/%s', $version->artifact->project->id, $version->artifact->id);

        return new self((string) $version->artifact->id, $version->number, $stable, \sprintf('%s/versions/%d', $stable, $version->number));
    }
}
