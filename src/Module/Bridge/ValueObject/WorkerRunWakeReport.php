<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

/**
 * A resume as the bridge reports it: the cause, when the session last spoke, and the final
 * usage of the first turn after the resume. Both of the last two are null when the harness
 * gives none.
 */
final readonly class WorkerRunWakeReport
{
    public function __construct(
        public WorkerRunWakeTrigger $trigger,
        public ?\DateTimeImmutable $lastTurnAt = null,
        public ?int $firstInputTokens = null,
        public ?int $firstCacheReadTokens = null,
        public ?int $firstCacheWriteTokens = null,
    ) {
    }

    /** The milliseconds from the last turn to the start of the run, or null when either time is unknown or the turn is later. */
    public function gapMs(?\DateTimeImmutable $startedAt): ?int
    {
        if (null === $startedAt || null === $this->lastTurnAt) {
            return null;
        }

        $gap = (int) $startedAt->format('Uv') - (int) $this->lastTurnAt->format('Uv');

        return $gap < 0 ? null : $gap;
    }
}
