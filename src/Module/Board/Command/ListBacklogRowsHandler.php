<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;

/** What a Backlog page after the first needs to refresh its own rows. */
final readonly class ListBacklogRowsHandler
{
    public function __construct(
        private CardRepository $cards,
        private BoardColumnRepository $boardColumns,
        private CardSiteReviewCommentRepository $cardSiteReviewComments,
    ) {
    }

    public function __invoke(ListBacklogRowsCommand $command): ListBacklogRowsView
    {
        $items = $this->cards->findBacklogMatchingByIds($command->backlog, $command->listQuery, $command->ids);
        $kept = array_map(static fn (Card $card): string => (string) $card->id, $items);
        $total = $this->cards->countInColumn($command->backlog);

        return new ListBacklogRowsView(
            items: $items,
            goneIds: array_values(array_diff($command->ids, $kept)),
            pendingComments: $this->cardSiteReviewComments->pendingCountsForCards($items),
            boardColumns: $this->boardColumns->findBoardColumns($command->backlog->project),
            newerCount: $this->cards->countBacklogMatchingCreatedAfter($command->backlog, $command->listQuery, $command->since),
            total: $total,
            filteredTotal: $command->listQuery->isNarrowed() ? $this->cards->countBacklogMatching($command->backlog, $command->listQuery) : $total,
        );
    }
}
