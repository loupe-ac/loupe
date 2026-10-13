<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** Finds the cards whose markers need a pass, so the minute task can queue them. */
interface CardsDueForMarkers
{
    /**
     * The open cards of bound projects whose markers are not stored yet, or whose due time is not after now.
     *
     * @return list<string> RFC 4122 card ids
     */
    public function findDueCardIds(\DateTimeImmutable $now, int $limit): array;
}
