<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Command;

use App\Module\Bridge\Repository\WorkRequestRepository;

/** Reads no live request, as when another request opens the work after the menu was computed. */
final class BlindWorkRequestRepository extends WorkRequestRepository
{
    #[\Override]
    public function findLiveRowsForCards(array $cardIds): array
    {
        return [];
    }
}
