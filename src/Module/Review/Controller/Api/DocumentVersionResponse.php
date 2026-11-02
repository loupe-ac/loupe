<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use App\Api\OpenApi\FieldsSchemaProcessor;
use App\Module\Review\Entity\DocumentVersion;
use OpenApi\Attributes as OA;

#[OA\Schema(required: ['versionNumber', 'createdAt', 'description', 'threadCount', 'markdown'], x: [FieldsSchemaProcessor::MARKER => self::PARTIAL])]
final readonly class DocumentVersionResponse
{
    /** The name of the partial component of this resource. */
    public const string PARTIAL = 'PartialDocumentVersion';

    /** The top-level names of a version, in the order of its row. */
    public const array FIELDS = ['versionNumber', 'createdAt', 'description', 'threadCount', 'markdown'];

    /** The names a version list refuses: its Markdown can hold a megabyte, so a list never reads it. */
    public const array REFUSED_IN_LIST = ['markdown', 'all'];

    public function __construct(
        #[OA\Property]
        public int $versionNumber,

        #[OA\Property(format: 'date-time')]
        public string $createdAt,

        #[OA\Property(description: 'What changed in this version.', nullable: true)]
        public ?string $description,

        #[OA\Property(description: 'The number of comment threads on this version.')]
        public int $threadCount,

        #[OA\Property(description: 'The Markdown source of this version.')]
        public string $markdown,
    ) {
    }

    public static function fromVersion(DocumentVersion $version, int $threadCount): self
    {
        return new self($version->versionNumber, $version->createdAt->format(\DATE_ATOM), $version->description, $threadCount, $version->markdownSource);
    }
}
