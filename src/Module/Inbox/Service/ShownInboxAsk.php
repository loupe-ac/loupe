<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Inbox\Entity\InboxAsk;

/** Picks the ask the inbox page shows for an item that several asks hold. */
final class ShownInboxAsk
{
    /**
     * An open ask, else the newest closed one, else null.
     *
     * @param list<InboxAsk> $asks every ask that holds the item, oldest first
     */
    public static function of(array $asks): ?InboxAsk
    {
        $open = array_find($asks, static fn (InboxAsk $ask): bool => null === $ask->closedAt);
        if (null !== $open) {
            return $open;
        }

        usort($asks, static fn (InboxAsk $a, InboxAsk $b): int => ($b->closedAt <=> $a->closedAt) ?: ((string) $a->id <=> (string) $b->id));

        return $asks[0] ?? null;
    }
}
