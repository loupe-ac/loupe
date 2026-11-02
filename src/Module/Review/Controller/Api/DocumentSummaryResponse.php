<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Tag;
use App\Module\Review\View\DocumentPageItem;
use OpenApi\Attributes as OA;

/** One document of a list: the current version by number and description, never its Markdown. */
#[OA\Schema(required: ['documentId', 'title', 'status', 'currentVersion', 'versionDescription', 'archived', 'tags', 'series', 'seriesOrdinal', 'createdAt'])]
final readonly class DocumentSummaryResponse
{
    /** @param list<string> $tags */
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $documentId,

        #[OA\Property]
        public string $title,

        #[OA\Property]
        public DocumentStatus $status,

        #[OA\Property]
        public int $currentVersion,

        #[OA\Property(description: 'What that version is.', nullable: true)]
        public ?string $versionDescription,

        #[OA\Property]
        public bool $archived,

        #[OA\Property(type: 'array', items: new OA\Items(type: 'string'))]
        public array $tags,

        #[OA\Property(nullable: true)]
        public ?string $series,

        #[OA\Property(nullable: true)]
        public ?int $seriesOrdinal,

        #[OA\Property(format: 'date-time')]
        public string $createdAt,
    ) {
    }

    public static function fromItem(DocumentPageItem $item): self
    {
        $document = $item->document;

        return new self(
            documentId: (string) $document->id,
            title: $document->title,
            status: $document->status,
            currentVersion: $item->versionNumber,
            versionDescription: $item->description,
            archived: null !== $document->archivedAt,
            tags: array_values(array_map(static fn (Tag $tag): string => $tag->name, $document->tags->toArray())),
            series: $document->series?->name,
            seriesOrdinal: $document->seriesOrdinal,
            createdAt: $document->createdAt->format(\DATE_ATOM),
        );
    }
}
