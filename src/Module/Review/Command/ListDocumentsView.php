<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Series;
use App\Module\Review\Entity\Tag;
use App\Module\Review\View\DocumentListItem;
use App\Module\Review\View\DocumentPosition;

final readonly class ListDocumentsView
{
    /**
     * @param list<DocumentListItem> $items
     * @param list<int|null>         $pageList
     * @param list<Tag>              $projectTags
     * @param list<Series>           $projectSeries
     * @param list<string>|null      $knownIds      the ids the filters keep, for a later page of a list that fits the cap
     */
    public function __construct(
        public array $items,
        public int $filteredTotal,
        public int $totalPages,
        public array $pageList,
        public array $projectTags,
        public array $projectSeries,
        public ?int $clampedPage,
        public ?DocumentPosition $newest = null,
        public ?array $knownIds = null,
    ) {
    }
}
