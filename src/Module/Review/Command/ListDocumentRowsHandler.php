<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Repository\DocumentRepository;
use App\Module\Review\Repository\SeriesRepository;
use App\Module\Review\Repository\TagRepository;
use App\Module\Review\Service\DocumentListItems;
use App\Module\Review\View\DocumentListItem;
use App\Utils\RowChanges;
use Symfony\Component\Uid\Uuid;

/**
 * Reads the rows that the open page of the documents list shows, by id. Page
 * one reads its own page again, so a row that entered it is inserted and a row
 * that left it is removed. A later page keeps its rows in place, and the count
 * names the matching documents created after the page loaded.
 */
final readonly class ListDocumentRowsHandler
{
    public const int MAX_IDS = 100;

    public function __construct(
        private DocumentRepository $documents,
        private DocumentListItems $items,
        private TagRepository $tags,
        private SeriesRepository $series,
        private ListDocumentsHandler $listDocuments,
    ) {
    }

    public function __invoke(ListDocumentRowsCommand $command): ListDocumentRowsView
    {
        $query = $command->listQuery;
        $ids = array_slice(array_values(array_map(strtolower(...), array_filter($command->ids, Uuid::isValid(...)))), 0, self::MAX_IDS);

        if (1 === $query->page) {
            $page = ($this->listDocuments)(new ListDocumentsCommand($command->project, $query));

            return new ListDocumentRowsView(
                items: [],
                removedIds: [],
                newerCount: 0,
                projectTags: $page->projectTags,
                projectSeries: $page->projectSeries,
                changes: RowChanges::between($page->items, static fn (DocumentListItem $item): string => (string) $item->document->id, $ids),
                page: $page,
            );
        }

        $matching = $this->documents->findMatchingByIds(
            $command->project,
            $ids,
            $query->includeArchived,
            $query->search,
            $query->status,
            $query->tagName,
            $query->seriesName,
        );
        $keptIds = array_map(static fn ($document): string => (string) $document->id, $matching);

        $removedIds = array_values(array_diff($ids, $keptIds));
        $newerCount = $this->documents->countMatchingCreatedSince(
            $command->project,
            $command->since,
            $command->sinceId,
            $ids,
            $query->includeArchived,
            $query->search,
            $query->status,
            $query->tagName,
            $query->seriesName,
        );
        $current = null === $command->knownIds ? null : $this->documents->findMatchingIds(
            $command->project,
            ListDocumentsHandler::MAX_KNOWN_IDS * 2,
            $query->includeArchived,
            $query->search,
            $query->status,
            $query->tagName,
            $query->seriesName,
        );
        if (null !== $command->knownIds && null !== $current) {
            // The page holds every id the filters kept at load, so a new match is any id it lacks.
            // A document that entered while another left still counts, and a left row never lowers it.
            $known = array_flip(array_map(strtolower(...), array_filter($command->knownIds, Uuid::isValid(...))));
            $newerCount = \count(array_filter($current, static fn (string $id): bool => !isset($known[$id])));
        } elseif (null !== $command->loadedTotal) {
            // A document that changed into the filters is older than the cursor, so only the
            // growth of the total shows it. Each shown row that left hides one entry of that growth.
            $total = $this->documents->countMatching($command->project, $query->includeArchived, $query->search, $query->status, $query->tagName, $query->seriesName);
            $newerCount = max($newerCount, $total - $command->loadedTotal + \count($removedIds));
        }

        return new ListDocumentRowsView(
            items: $this->items->of($matching),
            removedIds: $removedIds,
            newerCount: $newerCount,
            projectTags: $this->tags->findByProject($command->project),
            projectSeries: $this->series->findByProject($command->project),
        );
    }
}
