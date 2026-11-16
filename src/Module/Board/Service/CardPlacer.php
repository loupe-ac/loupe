<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Command\BoardPlacementContext;
use App\Module\Board\Command\CardPlacementView;
use App\Module\Board\Command\CardProgress;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;

/** Places one card against board-wide values that the caller has already read. */
final readonly class CardPlacer
{
    public function __construct(
        private BoardColumnCards $columnCards,
        private CardRepository $cards,
        private CardSiteReviewCommentRepository $cardSiteReviewComments,
        private CardDocumentRepository $cardDocuments,
        private LaneDecks $laneDecks,
    ) {
    }

    /** A null card is one that no longer exists. */
    public function place(BoardPlacementContext $context, ?Card $card): CardPlacementView
    {
        $backlog = $context->backlog;
        $columns = $context->columns;
        $windowStart = $context->windowStart;
        $counts = $context->counts;
        $terminalTotals = $context->terminalTotals;
        $types = $context->types;
        $laneEpicIds = $context->laneEpicIds;

        $columnId = null === $card ? null : $this->columnCards->shownColumnId($card, $windowStart);
        if (null === $columnId) {
            $laneEpicIds = [];
        }
        $laneIndex = null === $card ? false : array_search((string) $card->id, $laneEpicIds, true);
        $laneHead = \is_int($laneIndex);
        $laneAfter = $laneHead && $laneIndex > 0 ? $laneEpicIds[$laneIndex - 1] : null;

        // The Backlog is no column of the board, and a lane epic there still heads its lane.
        $index = null === $columnId ? null : array_find_key($columns, static fn (BoardColumn $column): bool => (string) $column->id === $columnId);
        $inBacklog = $laneHead && (string) $backlog->id === $columnId;
        if (null === $card || (null === $index && !$inBacklog)) {
            $parentId = null === $card?->parent ? null : (string) $card->parent->id;
            $deckEpic = (string) $backlog->id === $columnId && \in_array($parentId, $laneEpicIds, true) ? $parentId : null;

            return new CardPlacementView(null, null, null, null, 0, 0, $counts, $terminalTotals, null, null, false, null, deckEpic: $deckEpic);
        }

        $progress = null;
        if ($types->get($card->type)->children) {
            $children = $this->cards->childProgressOf($card);
            $progress = new CardProgress($children['done'], $children['total']);
        }

        $column = $backlog;
        $after = $rowAfter = $lane = null;
        if (null !== $index) {
            $column = $columns[$index];
            $previous = $this->columnCards->previousShown($card, $column, $windowStart);
            $rowAfter = $previous;
            for ($earlier = $index - 1; null === $rowAfter && $earlier >= 0; --$earlier) {
                $rowAfter = $this->columnCards->lastShown($columns[$earlier], $windowStart);
            }

            if ($laneHead) {
                $after = null;
            } elseif ([] === $laneEpicIds) {
                $after = $previous;
            } else {
                $parentId = null === $card->parent ? null : (string) $card->parent->id;
                $lane = null !== $parentId && \in_array($parentId, $laneEpicIds, true) ? $parentId : BoardLanes::OTHER;
                $after = $this->columnCards->previousShown($card, $column, $windowStart, $lane, $laneEpicIds);
            }
        }

        $markers = StoredCardMarkers::of($card);

        return new CardPlacementView(
            $card,
            $column,
            $after,
            $rowAfter,
            $this->cardSiteReviewComments->pendingCountForCard($card),
            $this->cardDocuments->countForCard($card),
            $counts,
            $terminalTotals,
            $progress,
            $lane,
            $laneHead,
            $laneAfter,
            $laneHead ? ($this->laneDecks->forEpics($backlog, [(string) $card->id])[(string) $card->id] ?? null) : null,
            markers: $markers->isEmpty() || $card->column->terminal ? null : $markers,
            inboxItem: $context->openInboxItems[(string) $card->id] ?? null,
        );
    }
}
