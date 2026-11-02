<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use App\Doctrine\SearchLanguage;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Tag;
use OpenApi\Attributes as OA;

/** A new document. Unlike a read, it also names the review page in the app. */
#[OA\Schema(required: ['documentId', 'reviewUrl', 'tags', 'series', 'seriesOrdinal', 'language', 'status'])]
final readonly class DocumentCreatedResponse
{
    /** @param list<string> $tags */
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $documentId,

        #[OA\Property(description: 'The review page in the app.', format: 'uri')]
        public string $reviewUrl,

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

    public static function fromDocument(Document $document, string $reviewUrl): self
    {
        return new self(
            documentId: (string) $document->id,
            reviewUrl: $reviewUrl,
            tags: array_values(array_map(static fn (Tag $tag): string => $tag->name, $document->tags->toArray())),
            series: $document->series?->name,
            seriesOrdinal: $document->seriesOrdinal,
            language: $document->searchLanguage,
            status: $document->status,
        );
    }
}
