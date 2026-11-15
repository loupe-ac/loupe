<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Repository\DocumentRepository;
use App\Module\Review\Service\DocumentListItems;
use Symfony\Component\Uid\Uuid;

/**
 * Reads the rows that a later page of the documents list shows, by id. A row
 * that the filters no longer keep is removed, so the rows of the page never
 * shift. The count names the matching documents created after the page loaded.
 */
final readonly class ListDocumentRowsHandler
{
    public const int MAX_IDS = 100;

    public function __construct(
        private DocumentRepository $documents,
        private DocumentListItems $items,
    ) {
    }

    public function __invoke(ListDocumentRowsCommand $command): ListDocumentRowsView
    {
        $query = $command->listQuery;
        $ids = array_slice(array_values(array_map(strtolower(...), array_filter($command->ids, Uuid::isValid(...)))), 0, self::MAX_IDS);

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
        if (null !== $command->loadedTotal) {
            // A document that changed into the filters is older than the cursor, so only the
            // growth of the total shows it. Each shown row that left hides one entry of that growth.
            $total = $this->documents->countMatching($command->project, $query->includeArchived, $query->search, $query->status, $query->tagName, $query->seriesName);
            $newerCount = max($newerCount, $total - $command->loadedTotal + \count($removedIds));
        }

        return new ListDocumentRowsView(
            items: $this->items->of($matching),
            removedIds: $removedIds,
            newerCount: $newerCount,
        );
    }
}
