<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/**
 * Keeps the markers of a card on the card, so a page reads them instead of working them out.
 * The card holds two sets. The board set has one entry per marker key. The inbox set has one entry
 * per thing for each marker that the template lists under `inbox`.
 */
interface CardMarkerStore
{
    /** @return list<StoredMarker> the board set, empty when the card has none or no pass stored it yet */
    public function read(Uuid $cardId): array;

    /** @return ?list<StoredMarker> the inbox set, or null when the pass of this version has not read the card yet */
    public function readInbox(Uuid $cardId): ?array;

    /**
     * Stores both sets and the time the next timed marker may change. A change of either set tells the open boards.
     *
     * @param list<StoredMarker> $markers the board set
     * @param list<StoredMarker> $inbox   the inbox set
     */
    public function write(Uuid $projectId, Uuid $cardId, array $markers, array $inbox, ?\DateTimeImmutable $dueAt): void;
}
