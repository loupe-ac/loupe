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
        if ($command->links) {
            $this->documents->preloadReferences($documents);
            $this->documents->preloadReferencedBy($documents);
        }
        $link = static fn (Document $linked): array => ['documentId' => (string) $linked->id, 'title' => $linked->title, 'archived' => null !== $linked->archivedAt];

        return new Page(
            array_map(static function (Document $document) use ($latest, $command, $link): DocumentPageItem {
                $meta = $latest[(string) $document->id] ?? throw new \LogicException('Document has no versions.');

                return new DocumentPageItem(
                    $document,
                    $meta['versionNumber'],
                    $meta['description'],
                    $command->links ? $document->references->map($link)->getValues() : [],
                    $command->links ? $document->referencedBy->map($link)->getValues() : [],
                );
            }, $documents),
            $page->next,
        );
    }
}
