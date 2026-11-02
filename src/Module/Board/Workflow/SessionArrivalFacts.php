<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

/** Whether a session run brought the card into its column. */
final readonly class SessionArrivalFacts
{
    public function __construct(
        public bool $arrivedInSession,
    ) {
    }
}
