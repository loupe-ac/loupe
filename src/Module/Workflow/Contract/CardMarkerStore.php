<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** Keeps the markers of a card on the card, so a page reads them instead of working them out. */
interface CardMarkerStore
{
    /** @return list<StoredMarker> empty when the card has none or no pass stored them yet */
    public function read(Uuid $cardId): array;

    /**
     * Stores the set and the time the next timed marker may change. A change of the set tells the open boards.
     *
     * @param list<StoredMarker> $markers
     */
    public function write(Uuid $projectId, Uuid $cardId, array $markers, ?\DateTimeImmutable $dueAt): void;
}
