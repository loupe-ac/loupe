<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Api\Pagination\Cursor;
use App\Api\Pagination\Page;
use App\Api\Problem\ApiProblemException;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Workflow\Contract\CardTypeCatalog;
use Symfony\Component\Uid\Uuid;

final readonly class PageBoardColumnsHandler
{
    /** The cursor sort of a list that reads in board order. */
    public const string BOARD_ORDER = 'position';

    public function __construct(
        private BoardColumnRepository $boardColumns,
        private CardTypeCatalog $catalog,
    ) {
    }

    /** @throws ApiProblemException `invalid_cursor` for a cursor whose position does not parse */
    public function __invoke(PageBoardColumnsCommand $command): PageBoardColumnsView
    {
        $rows = $this->boardColumns->findPageForProject(
            $command->project,
            $command->page->fetchSize(),
            null === $command->page->cursor ? null : self::positionOf($command->page->cursor),
        );

        return new PageBoardColumnsView(
            Page::fromRows($rows, $command->page, static fn (BoardColumn $column): Cursor => new Cursor(
                self::BOARD_ORDER,
                (string) $column->position,
                (string) $column->id,
            )),
            $this->catalog->forProject($command->project->requireId()),
        );
    }

    /** @return array{int, Uuid} */
    private static function positionOf(Cursor $cursor): array
    {
        $position = filter_var($cursor->sortValue, \FILTER_VALIDATE_INT);
        if (false === $position || !Uuid::isValid($cursor->id)) {
            throw ApiProblemException::invalidCursor('The cursor is broken. Start the list again without it.');
        }

        return [$position, Uuid::fromString($cursor->id)];
    }
}
