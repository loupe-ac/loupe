<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardRepository;
use App\Module\Project\Entity\Project;

/**
 * The cards the board shows in one column, in the order it shows them. The
 * board page reads whole columns here, and the one-card placement reads one
 * card and its neighbours, so the order and the column counts cannot drift apart.
 */
final readonly class BoardColumnCards
{
    public function __construct(
        private CardRepository $cards,
    ) {
    }

    /**
     * The oldest completion a terminal column shows now. Read it once per request.
     * A terminal column only ever grows, so it shows a recent slice and the
     * history page carries the rest.
     */
    public static function windowStart(int $days): \DateTimeImmutable
    {
        return new \DateTimeImmutable(\sprintf('-%d days', $days));
    }

    /**
     * The cards each column shows. The open columns are read in one query, and
     * each terminal column on its own.
     *
     * @param list<BoardColumn> $columns
     *
     * @return array<string, list<Card>> column id => the cards it shows
     */
    public function shownByColumn(array $columns, \DateTimeImmutable $windowStart): array
    {
        $open = array_values(array_filter($columns, static fn (BoardColumn $column): bool => !$column->terminal));
        $byColumn = array_fill_keys(array_map(static fn (BoardColumn $column): string => (string) $column->id, $open), []);
        foreach ($this->cards->findForBoard($open) as $card) {
            $byColumn[(string) $card->column->id][] = $card;
        }

        foreach ($columns as $column) {
            if ($column->terminal) {
                $byColumn[(string) $column->id] = $this->cards->findCompletedSince($column, $windowStart);
            }
        }

        return $byColumn;
    }

    /** The id of the column that shows the card, or null when the board does not show it. */
    public function shownColumnId(Card $card, \DateTimeImmutable $windowStart): ?string
    {
        return $this->cards->shownColumnIdOf($card, $windowStart);
    }

    /**
     * The id of the card shown just before this shown card in its column, or
     * null when it comes first. With a lane, only the cards of that lane count.
     *
     * @param list<string> $laneEpicIds
     */
    public function previousShown(Card $card, BoardColumn $column, \DateTimeImmutable $windowStart, ?string $lane = null, array $laneEpicIds = []): ?string
    {
        return $this->cards->previousShownIdOf($card, $column, $windowStart, $lane, $laneEpicIds);
    }

    /** The id of the last card the column shows, or null when it shows none. */
    public function lastShown(BoardColumn $column, \DateTimeImmutable $windowStart): ?string
    {
        return $this->cards->lastShownIdIn($column, $windowStart);
    }

    /**
     * For each column of the project that holds a card, every card it holds
     * and the cards it shows.
     *
     * @return array<string, array{total: int, shown: int}> column id => its counts
     */
    public function counts(Project $project, \DateTimeImmutable $windowStart): array
    {
        return $this->cards->shownCountsOf($project, $windowStart);
    }
}
