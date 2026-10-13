<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow;

/** The time a person held the agents of the card, null when the card has no hold. */
final readonly class HoldFacts
{
    public function __construct(
        public ?\DateTimeImmutable $heldAt,
    ) {
    }
}
