<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

/** What a test seeds for one card: its fix requests by reason, whether the delivered outcome ended it, and the hours to merge. */
final readonly class CardOutcomeFixture
{
    /** @param array<string, int> $fixRounds reason => fix requests with that reason */
    public function __construct(
        public array $fixRounds = [],
        public bool $merged = false,
        public ?float $hoursToMerge = null,
    ) {
    }
}
