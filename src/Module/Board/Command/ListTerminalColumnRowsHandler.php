<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardRepository;
use App\Utils\RowChanges;

/**
 * What the open terminal column page needs to refresh its own rows. Page one
 * reads its own page again, so a card that entered it is inserted and a card
 * that left it is removed. A later page keeps its rows in place and counts the
 * cards that finished since.
 */
final readonly class ListTerminalColumnRowsHandler
{
    public function __construct(
        private CardRepository $cards,
        private CardEventRepository $cardEvents,
        private ListTerminalColumnCardsHandler $listTerminalColumnCards,
    ) {
    }

    public function __invoke(ListTerminalColumnRowsCommand $command): ListTerminalColumnRowsView
    {
        if (1 === $command->page) {
            $page = ($this->listTerminalColumnCards)(new ListTerminalColumnCardsCommand($command->column));

            return new ListTerminalColumnRowsView(
                items: [],
                goneIds: [],
                newerCount: 0,
                total: $page->total,
                changes: RowChanges::between($page->items, static fn (Card $card): string => (string) $card->id, $command->ids),
                page: $page,
            );
        }

        $since = $command->since ?? throw new \LogicException('A page after the first needs the moment it rendered.');
        $items = $this->cards->findCompletedByIds($command->column, $command->ids);
        $kept = array_map(static fn (Card $card): string => (string) $card->id, $items);

        return new ListTerminalColumnRowsView(
            items: $items,
            goneIds: array_values(array_diff($command->ids, $kept)),
            newerCount: $this->cards->countCompletedAfter(
                $command->column,
                $since,
                $this->cardEvents->findCardIdsMovedIntoColumnAfter($command->column, $since),
                $command->ids,
            ),
            total: $this->cards->countInColumn($command->column),
        );
    }
}
