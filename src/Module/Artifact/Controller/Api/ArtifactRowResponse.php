<?php

declare(strict_types=1);

namespace App\Module\Artifact\Controller\Api;

use App\Module\Artifact\Command\PagedArtifact;
use App\Module\Artifact\Entity\Artifact;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * An artifact in a list, as the public API shows it with no `fields`: the artifact with a short
 * form of its versions and of the files of the latest version, and without the signed page link.
 */
#[OA\Schema(required: ['artifactId', 'name', 'archived', 'archivedAt', 'sourceLink', 'createdAt', 'url', 'versions', 'selectedVersion', 'files'])]
final readonly class ArtifactRowResponse
{
    /**
     * @param list<array<string, mixed>> $versions
     * @param list<array<string, mixed>> $files
     */
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $artifactId,

        #[OA\Property(maxLength: Artifact::MAX_NAME_LENGTH)]
        public string $name,
        public bool $archived,

        #[OA\Property(format: 'date-time', nullable: true)]
        public ?string $archivedAt,

        #[OA\Property(nullable: true)]
        public ?string $sourceLink,

        #[OA\Property(format: 'date-time')]
        public string $createdAt,

        #[OA\Property(description: 'The artifact page in the app, at the newest version.', format: 'uri')]
        public string $url,

        #[OA\Property(description: 'Each version as its number and the time it was stored. Name `versions` in `fields` for the notes, the counts and the sizes.', type: 'array', items: new OA\Items(ref: new Model(type: ArtifactVersionRefResponse::class)))]
        public array $versions,

        #[OA\Property(description: 'The latest version, whose files are listed.', nullable: true)]
        public ?int $selectedVersion,

        #[OA\Property(description: 'The paths of the files of that version. Name `files` in `fields` for the content types and the sizes.', type: 'array', items: new OA\Items(ref: new Model(type: ArtifactFileRefResponse::class)))]
        public array $files,
    ) {
    }

    public static function fromPaged(PagedArtifact $paged, string $url): self
    {
        $artifact = $paged->artifact;

        return new self(
            artifactId: (string) $artifact->id,
            name: $artifact->name,
            archived: null !== $artifact->archivedAt,
            archivedAt: $artifact->archivedAt?->format(\DATE_ATOM),
            sourceLink: $artifact->sourceLink,
            createdAt: $artifact->createdAt->format(\DATE_ATOM),
            url: $url,
            versions: $paged->versions,
            selectedVersion: $paged->selectedVersion,
            files: $paged->files,
        );
    }

    /**
     * The row as a map in the order of the whole artifact. The signed link sits between the
     * selected version and the files, and only a row that asked for it holds the key.
     *
     * @return array<string, mixed>
     */
    public function withPageLink(?string $pageLink): array
    {
        $row = get_object_vars($this);
        $before = array_slice($row, 0, \count($row) - 1, true);

        return $before + ['pageLink' => $pageLink] + ['files' => $row['files']];
    }
}
