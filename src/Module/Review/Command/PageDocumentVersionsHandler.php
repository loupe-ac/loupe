<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Api\Pagination\Cursor;
use App\Api\Pagination\Page;
use App\Api\Problem\ApiProblemException;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\View\DocumentVersionPageItem;
use Symfony\Component\Uid\Uuid;

final readonly class PageDocumentVersionsHandler
{
    public function __construct(
        private DocumentVersionRepository $documentVersions,
        private CommentRepository $comments,
    ) {
    }

    /**
     * @return Page<DocumentVersionPageItem>
     *
     * @throws ApiProblemException `invalid_cursor` for a cursor whose position does not parse
     */
    public function __invoke(PageDocumentVersionsCommand $command): Page
    {
        $cursor = $command->page->cursor;
        if (null !== $cursor && (1 !== preg_match('/^[1-9]\d{0,9}$/', $cursor->sortValue) || !Uuid::isValid($cursor->id))) {
            throw ApiProblemException::invalidCursor('The cursor is broken. Start the list again without it.');
        }

        $rows = $this->documentVersions->findMetaPageByDocument(
            $command->document,
            $command->page->fetchSize(),
            $command->sort->newestFirst(),
            null === $cursor ? null : (int) $cursor->sortValue,
        );

        $page = Page::fromRows($rows, $command->page, fn (array $row): Cursor => new Cursor($command->sort->value, (string) $row['versionNumber'], $row['id']));

        $signals = $this->comments->signalsByVersions(array_map(static fn (array $row): string => $row['id'], $page->items));

        return new Page(
            array_map(static fn (array $row): DocumentVersionPageItem => new DocumentVersionPageItem(
                $row['id'],
                $row['versionNumber'],
                $row['createdAt'],
                $row['description'],
                ($signals[$row['id']] ?? null)?->threadCount() ?? 0,
            ), $page->items),
            $page->next,
        );
    }
}
