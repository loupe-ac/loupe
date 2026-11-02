<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Api\Pagination\Page;
use App\Api\Problem\ApiProblemException;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\DocumentRepository;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\View\DocumentPageItem;
use App\Module\Review\View\DocumentPageRow;
use App\Module\Review\View\DocumentPosition;

final readonly class PageDocumentsHandler
{
    public function __construct(
        private DocumentRepository $documents,
        private DocumentVersionRepository $documentVersions,
    ) {
    }

    /**
     * @return Page<DocumentPageItem>
     *
     * @throws ApiProblemException `invalid_cursor` for a cursor whose position does not parse
     */
    public function __invoke(PageDocumentsCommand $command): Page
    {
        $query = $command->query;
        $cursor = $command->page->cursor;
        $rows = $this->documents->findPageByProject(
            $command->project,
            $query,
            $command->page->fetchSize(),
            null === $cursor ? null : DocumentPosition::fromCursor($query->order, $cursor),
        );

        $page = Page::fromRows($rows, $command->page, static fn (DocumentPageRow $row) => DocumentPosition::of($row)->toCursor($query->order, $query));

        $documents = array_map(static fn (DocumentPageRow $row): Document => $row->document, $page->items);
        $this->documents->preloadTags($documents);
        $latest = $this->documentVersions->findLatestMetaByDocuments($documents);

        return new Page(
            array_map(static function (Document $document) use ($latest): DocumentPageItem {
                $meta = $latest[(string) $document->id] ?? throw new \LogicException('Document has no versions.');

                return new DocumentPageItem($document, $meta['versionNumber'], $meta['description']);
            }, $documents),
            $page->next,
        );
    }
}
