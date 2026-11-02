<?php

declare(strict_types=1);

namespace App\Module\Artifact\Controller\Api;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/** One artifact with its versions and the files of one version. DescribeArtifactHandler builds the real body. */
#[OA\Schema(required: ['artifactId', 'name', 'archived', 'archivedAt', 'sourceLink', 'createdAt', 'path', 'versions', 'selectedVersion', 'pageLink', 'files'])]
final readonly class ArtifactResponse
{
    /**
     * @param list<ArtifactVersionResponse> $versions
     * @param list<ArtifactFileResponse>    $files
     */
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $artifactId,
        public string $name,
        public bool $archived,

        #[OA\Property(format: 'date-time', nullable: true)]
        public ?string $archivedAt,

        #[OA\Property(nullable: true)]
        public ?string $sourceLink,

        #[OA\Property(format: 'date-time')]
        public string $createdAt,

        #[OA\Property(description: 'The path of the artifact page in the app.')]
        public string $path,

        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: ArtifactVersionResponse::class)))]
        public array $versions,

        #[OA\Property(description: 'The version whose files are listed.', nullable: true)]
        public ?int $selectedVersion,

        #[OA\Property(description: 'A full address of the entry page of that version, valid for one hour, or null when the version has none.', format: 'uri', nullable: true)]
        public ?string $pageLink,

        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: ArtifactFileResponse::class)))]
        public array $files,
    ) {
    }
}
