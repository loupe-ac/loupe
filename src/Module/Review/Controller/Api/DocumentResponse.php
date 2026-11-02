<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use App\Doctrine\SearchLanguage;
use App\Module\Review\Command\ShowDocumentDataHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * A document with the Markdown of its current version.
 *
 * @phpstan-import-type DocumentPayload from ShowDocumentDataHandler
 */
#[OA\Schema(required: ['documentId', 'title', 'language', 'status', 'archived', 'archiveReason', 'version', 'versionDescription', 'markdown', 'tags', 'series', 'seriesOrdinal', 'references', 'referencedBy', 'createdAt'])]
final readonly class DocumentResponse
{
    /** The top-level names of a document, in the order of its row. */
    public const array FIELDS = ['documentId', 'title', 'language', 'status', 'archived', 'archiveReason', 'version', 'versionDescription', 'markdown', 'tags', 'series', 'seriesOrdinal', 'references', 'referencedBy', 'createdAt'];

    /** The names a document list refuses: its Markdown can hold a megabyte, so a list never reads it. */
    public const array REFUSED_IN_LIST = ['markdown', 'all'];

    /**
     * @param list<string>               $tags
     * @param list<DocumentLinkResponse> $references
     * @param list<DocumentLinkResponse> $referencedBy
     */
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $documentId,

        #[OA\Property(maxLength: Document::MAX_TITLE_LENGTH)]
        public string $title,

        #[OA\Property]
        public SearchLanguage $language,

        #[OA\Property]
        public DocumentStatus $status,

        #[OA\Property]
        public bool $archived,

        #[OA\Property(nullable: true)]
        public ?string $archiveReason,

        #[OA\Property(description: 'The current version number.')]
        public int $version,

        #[OA\Property(nullable: true)]
        public ?string $versionDescription,

        #[OA\Property(description: 'The Markdown source of the current version.')]
        public string $markdown,

        #[OA\Property(type: 'array', items: new OA\Items(type: 'string'))]
        public array $tags,

        #[OA\Property(nullable: true)]
        public ?string $series,

        #[OA\Property(nullable: true)]
        public ?int $seriesOrdinal,

        #[OA\Property(description: 'The documents this one points at. Only this direction is writable.', type: 'array', items: new OA\Items(ref: new Model(type: DocumentLinkResponse::class)))]
        public array $references,

        #[OA\Property(description: 'The documents that point at this one.', type: 'array', items: new OA\Items(ref: new Model(type: DocumentLinkResponse::class)))]
        public array $referencedBy,

        #[OA\Property(format: 'date-time')]
        public string $createdAt,
    ) {
    }

    /** @param DocumentPayload $payload */
    public static function fromPayload(array $payload, Document $document): self
    {
        $link = static fn (array $row): DocumentLinkResponse => new DocumentLinkResponse($row['documentId'], $row['title'], $row['archived']);

        return new self(
            documentId: $payload['documentId'],
            title: $payload['title'],
            language: $document->searchLanguage,
            status: $document->status,
            archived: $payload['archived'],
            archiveReason: $payload['archiveReason'],
            version: $payload['version'],
            versionDescription: $payload['versionDescription'],
            markdown: $payload['markdown'],
            tags: $payload['tags'],
            series: $payload['series'],
            seriesOrdinal: $payload['seriesOrdinal'],
            references: array_map($link, $payload['references']),
            referencedBy: array_map($link, $payload['referencedBy']),
            createdAt: $document->createdAt->format(\DATE_ATOM),
        );
    }
}
