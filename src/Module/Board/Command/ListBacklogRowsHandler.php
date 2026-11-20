<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Utils\RowChanges;

/**
 * What the open Backlog page needs to refresh its own rows. Page one reads its
 * own page again, so a card that entered it is inserted and a card that left it
 * is removed. A later page keeps its rows in place and counts the new cards.
 */
final readonly class ListBacklogRowsHandler
{
    public function __construct(
        private CardRepository $cards,
        private CardEventRepository $cardEvents,
        private BoardColumnRepository $boardColumns,
        private CardSiteReviewCommentRepository $cardSiteReviewComments,
        private ListBacklogCardsHandler $listBacklogCards,
    ) {
    }

    public function __invoke(ListBacklogRowsCommand $command): ListBacklogRowsView
    {
        if (1 === $command->listQuery->page) {
            $page = ($this->listBacklogCards)(new ListBacklogCardsCommand($command->backlog, $command->listQuery));

            return new ListBacklogRowsView(
                items: [],
                goneIds: [],
                pendingComments: $page->pendingComments,
                boardColumns: $page->boardColumns,
                nextColumn: $page->nextColumn,
                newerCount: 0,
                total: $page->total,
                filteredTotal: $page->filteredTotal,
                epics: $page->epics,
                changes: RowChanges::between($page->items, static fn (Card $card): string => (string) $card->id, $command->ids),
                page: $page,
            );
        }

        $since = $command->since ?? throw new \LogicException('A page after the first needs the moment it rendered.');
        $items = $this->cards->findBacklogMatchingByIds($command->backlog, $command->listQuery, $command->ids);
        $kept = array_map(static fn (Card $card): string => (string) $card->id, $items);
        $total = $this->cards->countInColumn($command->backlog);
        $boardColumns = $this->boardColumns->findBoardColumns($command->backlog->project);

        return new ListBacklogRowsView(
            items: $items,
            goneIds: array_values(array_diff($command->ids, $kept)),
            pendingComments: $this->cardSiteReviewComments->pendingCountsForCards($items),
            boardColumns: $boardColumns,
            nextColumn: array_find($boardColumns, static fn (BoardColumn $column): bool => !$column->terminal),
            newerCount: $this->cards->countBacklogMatchingArrivedAfter(
                $command->backlog,
                $command->listQuery,
                $since,
                $this->cardEvents->findCardIdsMovedIntoColumnAfter($command->backlog, $since),
                $command->ids,
            ),
            total: $total,
            filteredTotal: $command->listQuery->isNarrowed() ? $this->cards->countBacklogMatching($command->backlog, $command->listQuery) : $total,
            epics: $this->cards->findEpicsOfColumn($command->backlog),
        );
    }
}
