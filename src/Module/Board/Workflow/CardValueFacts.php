<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

/** The values a card holds, for the keys its template declares. A key with no valid value is absent. */
final readonly class CardValueFacts
{
    /** @param array<string, mixed> $values the held value of each key */
    public function __construct(
        public array $values,
    ) {
    }
}
