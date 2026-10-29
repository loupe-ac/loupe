<?php

declare(strict_types=1);

namespace App\Module\Workflow\Fact;

/** Since when a rule of the card waits because the project holds its new work, as the engine stamped it. Null when no rule waits. */
final readonly class WorkHoldFacts
{
    public function __construct(
        public ?\DateTimeImmutable $since,
    ) {
    }
}
