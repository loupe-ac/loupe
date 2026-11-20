<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Series;
use App\Module\Review\Entity\Tag;
use App\Module\Review\View\DocumentListItem;

final readonly class ListDocumentRowsView
{
    /**
     * @param list<DocumentListItem> $items         the shown rows that the filters still keep
     * @param list<string>           $removedIds    the shown rows that the filters no longer keep, or that are gone
     * @param list<Tag>              $projectTags
     * @param list<Series>           $projectSeries
     */
    public function __construct(
        public array $items,
        public array $removedIds,
        public int $newerCount,
        public array $projectTags,
        public array $projectSeries,
    ) {
    }
}
