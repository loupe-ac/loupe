<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\View\DocumentListItem;

final readonly class ListDocumentRowsView
{
    /**
     * @param list<DocumentListItem> $items      the shown rows that the filters still keep
     * @param list<string>           $removedIds the shown rows that the filters no longer keep, or that are gone
     */
    public function __construct(
        public array $items,
        public array $removedIds,
        public int $newerCount,
    ) {
    }
}
