<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;

/** What a Backlog page after the first needs to refresh its own rows. */
final readonly class ListBacklogRowsHandler
{
    public function __construct(
        private CardRepository $cards,
        private CardEventRepository $cardEvents,
        private BoardColumnRepository $boardColumns,
        private CardSiteReviewCommentRepository $cardSiteReviewComments,
    ) {
    }

    public function __invoke(ListBacklogRowsCommand $command): ListBacklogRowsView
    {
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
                $command->since,
                $this->cardEvents->findCardIdsMovedIntoColumnAfter($command->backlog, $command->since),
                $command->ids,
            ),
            total: $total,
            filteredTotal: $command->listQuery->isNarrowed() ? $this->cards->countBacklogMatching($command->backlog, $command->listQuery) : $total,
            epics: $this->cards->findEpicsOfColumn($command->backlog),
        );
    }
}
