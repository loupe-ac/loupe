<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Repository\DocumentRepository;
use App\Module\Review\Repository\SeriesRepository;
use App\Module\Review\Repository\TagRepository;
use App\Module\Review\Service\DocumentListItems;
use App\Utils\PageList;

final readonly class ListDocumentsHandler
{
    public const int PER_PAGE = 20;

    /**
     * The ceiling on a caller-chosen page size. The web list does not choose
     * one; document_list does, and an agent may ask for any number.
     */
    public const int MAX_PER_PAGE = 100;

    public function __construct(
        private DocumentRepository $documents,
        private DocumentListItems $items,
        private TagRepository $tags,
        private SeriesRepository $series,
    ) {
    }

    public function __invoke(ListDocumentsCommand $command): ListDocumentsView
    {
        $listQuery = $command->listQuery;
        // Clamped rather than refused: an out-of-range page size should be
        // brought into range, not fail the call.
        $perPage = min(self::MAX_PER_PAGE, max(1, $command->perPage));
        $paginator = $this->documents->findPaginatedByProject(
            $command->project,
            $listQuery->page,
            $perPage,
            $listQuery->includeArchived,
            $listQuery->search,
            $listQuery->status,
            $listQuery->tagName,
            $listQuery->seriesName,
        );
        $total = count($paginator);
        $totalPages = max(1, (int) ceil($total / $perPage));

        $documents = iterator_to_array($paginator, false);

        return new ListDocumentsView(
            items: $this->items->of($documents),
            filteredTotal: $total,
            totalPages: $totalPages,
            pageList: PageList::build($listQuery->page, $totalPages),
            projectTags: $this->tags->findByProject($command->project),
            projectSeries: $this->series->findByProject($command->project),
            clampedPage: PageList::clampedPage($listQuery->page, $total, $perPage),
        );
    }
}
