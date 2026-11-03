<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use App\Doctrine\SearchLanguage;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Tag;
use OpenApi\Attributes as OA;

/** A new document. */
#[OA\Schema(required: ['documentId', 'url', 'tags', 'series', 'seriesOrdinal', 'language', 'status'])]
final readonly class DocumentCreatedResponse
{
    /** @param list<string> $tags */
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $documentId,

        #[OA\Property(description: 'The review page of the document in the app, at the latest version.', format: 'uri')]
        public string $url,

        #[OA\Property(type: 'array', items: new OA\Items(type: 'string'))]
        public array $tags,

        #[OA\Property(nullable: true)]
        public ?string $series,

        #[OA\Property(nullable: true)]
        public ?int $seriesOrdinal,

        #[OA\Property]
        public SearchLanguage $language,

        #[OA\Property]
        public DocumentStatus $status,
    ) {
    }

    public static function fromDocument(Document $document, string $url): self
    {
        return new self(
            documentId: (string) $document->id,
            url: $url,
            tags: array_values(array_map(static fn (Tag $tag): string => $tag->name, $document->tags->toArray())),
            series: $document->series?->name,
            seriesOrdinal: $document->seriesOrdinal,
            language: $document->searchLanguage,
            status: $document->status,
        );
    }
}
