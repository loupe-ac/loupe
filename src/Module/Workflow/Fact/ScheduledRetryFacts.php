<?php

declare(strict_types=1);

namespace App\Module\Workflow\Fact;

/** The due times of the retries that refused rules of the card earned, as the rule states store them. */
final readonly class ScheduledRetryFacts
{
    /** @param list<\DateTimeImmutable> $dueAts the earliest first; a past one may stay until the next pass clears it */
    public function __construct(
        public array $dueAts,
    ) {
    }

    /** The earliest due time after $now, or null when no retry is still to come. */
    public function nextAfter(\DateTimeImmutable $now): ?\DateTimeImmutable
    {
        return array_find($this->dueAts, static fn (\DateTimeImmutable $dueAt): bool => $dueAt > $now);
    }
}
