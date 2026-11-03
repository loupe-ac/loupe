<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use App\Module\Review\View\DocumentVersionPageItem;
use OpenApi\Attributes as OA;

/** One version of a list: the whole version without its Markdown, which a list never reads. */
#[OA\Schema(required: ['versionNumber', 'createdAt', 'description', 'threadCount'])]
final readonly class DocumentVersionRowResponse
{
    public function __construct(
        #[OA\Property]
        public int $versionNumber,

        #[OA\Property(format: 'date-time')]
        public string $createdAt,

        #[OA\Property(description: 'What changed in this version.', nullable: true)]
        public ?string $description,

        #[OA\Property(description: 'The number of comment threads on this version.')]
        public int $threadCount,
    ) {
    }

    public static function fromItem(DocumentVersionPageItem $item): self
    {
        return new self($item->versionNumber, $item->createdAt->format(\DATE_ATOM), $item->description, $item->threadCount);
    }
}
