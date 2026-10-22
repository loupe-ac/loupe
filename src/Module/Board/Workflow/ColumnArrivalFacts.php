<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

/** When the card arrived in its current column: the time of its newest created or moved row. */
final readonly class ColumnArrivalFacts
{
    public function __construct(
        public ?\DateTimeImmutable $arrivedAt,
    ) {
    }
}
