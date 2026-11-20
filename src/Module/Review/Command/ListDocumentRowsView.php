<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Series;
use App\Module\Review\Entity\Tag;
use App\Module\Review\View\DocumentListItem;
use App\Utils\RowChanges;

final readonly class ListDocumentRowsView
{
    /**
     * @param list<DocumentListItem>        $items         the shown rows that the filters still keep
     * @param list<string>                  $removedIds    the shown rows that the filters no longer keep, or that are gone
     * @param list<Tag>                     $projectTags
     * @param list<Series>                  $projectSeries
     * @param ?RowChanges<DocumentListItem> $changes       what page one changes on the screen, or null on a later page
     * @param ?ListDocumentsView            $page          page one as the list reads it, or null on a later page
     */
    public function __construct(
        public array $items,
        public array $removedIds,
        public int $newerCount,
        public array $projectTags,
        public array $projectSeries,
        public ?RowChanges $changes = null,
        public ?ListDocumentsView $page = null,
    ) {
    }
}
