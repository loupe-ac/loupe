<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

/** The time of the newest event of the card, null when it has none. */
final readonly class CardActivityFacts
{
    public function __construct(
        public ?\DateTimeImmutable $newestEventAt,
    ) {
    }
}
