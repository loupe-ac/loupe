<?php

declare(strict_types=1);

namespace App\Module\Review\View;

use App\Module\Review\Entity\DocumentStatus;

/** The filters of one page of the document list, with the names already normalised. */
final readonly class DocumentPageQuery
{
    public function __construct(
        public DocumentPageOrder $order,
        public bool $includeArchived = false,
        public ?string $search = null,
        public ?DocumentStatus $status = null,
        public ?string $tagName = null,
        public ?string $seriesName = null,
    ) {
    }

    /**
     * What a cursor must stay valid for. The order is part of the cursor already.
     *
     * @return array<string, scalar|null>
     */
    public function filters(): array
    {
        return [
            'archived' => $this->includeArchived ? 1 : null,
            'q' => $this->search,
            'status' => $this->status?->value,
            'tag' => $this->tagName,
            'series' => $this->seriesName,
        ];
    }
}
