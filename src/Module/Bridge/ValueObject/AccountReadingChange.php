<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

/** What a batch of account readings changed. Each change except None alters what the Agents page shows. */
enum AccountReadingChange
{
    case None;

    /** The bridge reported stored values again, so only their age moved. */
    case Seen;

    /** A new reading was stored, or a Try now override closed. */
    case Stored;

    public function refreshesPage(): bool
    {
        return self::None !== $this;
    }
}
