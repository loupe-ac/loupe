<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

/** The time of the newest history event of any child of the card, and whether a child has an open worker run. */
final readonly class ChildActivityFacts
{
    public function __construct(
        public ?\DateTimeImmutable $newestEventAt,
        public bool $runOpen,
    ) {
    }
}
