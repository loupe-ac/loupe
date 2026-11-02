<?php

declare(strict_types=1);

namespace App\Module\Artifact\Controller\Api;

use App\Module\Artifact\Command\PagedArtifact;
use App\Module\Artifact\Entity\Artifact;
use OpenApi\Attributes as OA;

/** An artifact in a list, as the public API shows it. */
#[OA\Schema(required: ['artifactId', 'name', 'latestVersion', 'archived', 'sourceLink', 'createdAt', 'url'])]
final readonly class ArtifactSummaryResponse
{
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $artifactId,

        #[OA\Property(maxLength: Artifact::MAX_NAME_LENGTH)]
        public string $name,
        public int $latestVersion,
        public bool $archived,

        #[OA\Property(nullable: true)]
        public ?string $sourceLink,

        #[OA\Property(format: 'date-time')]
        public string $createdAt,

        #[OA\Property(description: 'The artifact page in the app, at the newest version.', format: 'uri')]
        public string $url,
    ) {
    }

    public static function fromPaged(PagedArtifact $paged, string $url): self
    {
        $artifact = $paged->artifact;

        return new self(
            artifactId: (string) $artifact->id,
            name: $artifact->name,
            latestVersion: $paged->latestVersion,
            archived: null !== $artifact->archivedAt,
            sourceLink: $artifact->sourceLink,
            createdAt: $artifact->createdAt->format(\DATE_ATOM),
            url: $url,
        );
    }
}
