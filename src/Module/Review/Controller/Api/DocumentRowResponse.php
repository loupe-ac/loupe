<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use App\Doctrine\SearchLanguage;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Tag;
use App\Module\Review\View\DocumentPageItem;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/** One document of a list: the whole document without its Markdown, which a list never reads. */
#[OA\Schema(required: ['documentId', 'url', 'title', 'language', 'status', 'archived', 'archiveReason', 'version', 'versionDescription', 'tags', 'series', 'seriesOrdinal', 'references', 'referencedBy', 'createdAt'])]
final readonly class DocumentRowResponse
{
    /**
     * @param list<string>               $tags
     * @param list<DocumentLinkResponse> $references
     * @param list<DocumentLinkResponse> $referencedBy
     */
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $documentId,

        #[OA\Property(description: 'The review page of the document in the app, at the latest version.', format: 'uri')]
        public string $url,

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

        #[OA\Property(description: 'What that version is.', nullable: true)]
        public ?string $versionDescription,

        #[OA\Property(type: 'array', items: new OA\Items(type: 'string'))]
        public array $tags,

        #[OA\Property(nullable: true)]
        public ?string $series,

        #[OA\Property(nullable: true)]
        public ?int $seriesOrdinal,

        #[OA\Property(description: 'The documents this one points at.', type: 'array', items: new OA\Items(ref: new Model(type: DocumentLinkResponse::class)))]
        public array $references,

        #[OA\Property(description: 'The documents that point at this one.', type: 'array', items: new OA\Items(ref: new Model(type: DocumentLinkResponse::class)))]
        public array $referencedBy,

        #[OA\Property(format: 'date-time')]
        public string $createdAt,
    ) {
    }

    public static function fromItem(DocumentPageItem $item, string $url): self
    {
        $document = $item->document;

        return new self(
            documentId: (string) $document->id,
            url: $url,
            title: $document->title,
            language: $document->searchLanguage,
            status: $document->status,
            archived: null !== $document->archivedAt,
            archiveReason: $document->archiveReason,
            version: $item->versionNumber,
            versionDescription: $item->description,
            tags: array_values(array_map(static fn (Tag $tag): string => $tag->name, $document->tags->toArray())),
            series: $document->series?->name,
            seriesOrdinal: $document->seriesOrdinal,
            references: array_map(static fn (array $link): DocumentLinkResponse => new DocumentLinkResponse($link['documentId'], $link['title'], $link['archived']), $item->references),
            referencedBy: array_map(static fn (array $link): DocumentLinkResponse => new DocumentLinkResponse($link['documentId'], $link['title'], $link['archived']), $item->referencedBy),
            createdAt: $document->createdAt->format(\DATE_ATOM),
        );
    }
}
