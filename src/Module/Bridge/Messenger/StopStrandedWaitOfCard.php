<?php

declare(strict_types=1);

namespace App\Module\Bridge\Messenger;

use App\Module\Bridge\ValueObject\WorkerWaitEndReason;

/** A card changed while the wait of its offline bridge could not resume, so the parked run of that wait stops. */
final readonly class StopStrandedWaitOfCard
{
    public function __construct(
        public string $waitId,
        public WorkerWaitEndReason $reason,
    ) {
    }
}
