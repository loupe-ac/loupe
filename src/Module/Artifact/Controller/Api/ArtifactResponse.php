<?php

declare(strict_types=1);

namespace App\Module\Artifact\Controller\Api;

use App\Api\OpenApi\FieldsSchemaProcessor;
use App\Module\Artifact\Entity\Artifact;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/** One artifact with its versions and the files of one version. DescribeArtifactHandler builds the real body. */
#[OA\Schema(required: ['artifactId', 'name', 'archived', 'archivedAt', 'sourceLink', 'createdAt', 'url', 'versions', 'selectedVersion', 'pageLink', 'files'], x: [FieldsSchemaProcessor::MARKER => self::PARTIAL])]
final readonly class ArtifactResponse
{
    /** The name of the partial component of this resource. */
    public const string PARTIAL = 'PartialArtifact';

    /** The top-level names of an artifact, in the order of its row. */
    public const array FIELDS = ['artifactId', 'name', 'archived', 'archivedAt', 'sourceLink', 'createdAt', 'url', 'versions', 'selectedVersion', 'pageLink', 'files'];

    /**
     * @param list<ArtifactVersionResponse> $versions
     * @param list<ArtifactFileResponse>    $files
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

        #[OA\Property(description: 'The artifact page in the app, at the newest version. It needs a sign-in. `pageLink` needs none.', format: 'uri')]
        public string $url,

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
