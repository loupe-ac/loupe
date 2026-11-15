<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardRepository;

/** What a terminal column page after the first needs to refresh its own rows. */
final readonly class ListTerminalColumnRowsHandler
{
    public function __construct(
        private CardRepository $cards,
        private CardEventRepository $cardEvents,
    ) {
    }

    public function __invoke(ListTerminalColumnRowsCommand $command): ListTerminalColumnRowsView
    {
        $items = $this->cards->findCompletedByIds($command->column, $command->ids);
        $kept = array_map(static fn (Card $card): string => (string) $card->id, $items);

        return new ListTerminalColumnRowsView(
            items: $items,
            goneIds: array_values(array_diff($command->ids, $kept)),
            newerCount: $this->cards->countCompletedAfter(
                $command->column,
                $command->since,
                $this->cardEvents->findCardIdsMovedIntoColumnAfter($command->column, $command->since),
                $command->ids,
            ),
            total: $this->cards->countInColumn($command->column),
        );
    }
}
