<?php

declare(strict_types=1);

namespace App\Module\Inbox\Entity;

/** Every state except Open is closed. */
enum InboxItemState: string
{
    case Open = 'open';
    case Resolved = 'resolved';
    case Declined = 'declined';
    case Cancelled = 'cancelled';

    /** An owner response stays open to change until a closed ask holds the item. A cancelled item is never the owner's to change. */
    public function acceptsResponse(bool $heldByClosedAsk): bool
    {
        return match ($this) {
            self::Open => true,
            self::Cancelled => false,
            self::Resolved, self::Declined => !$heldByClosedAsk,
        };
    }
}
