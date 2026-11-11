<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

/** A card of a reorder answer, with the rank it holds now. */
final readonly class ReorderedCard
{
    public function __construct(
        public int $number,
        public string $title,
        public int $rank,
    ) {
    }
}
