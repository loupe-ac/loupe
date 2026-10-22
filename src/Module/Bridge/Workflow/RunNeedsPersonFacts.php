<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow;

use App\Module\Bridge\ValueObject\WorkerRunState;

/** The state of the newest run of the card, and the time of the newest move of the card. */
final readonly class RunNeedsPersonFacts
{
    public function __construct(
        public ?string $runId,
        public ?WorkerRunState $state,
        public ?\DateTimeImmutable $endedAt,
        public ?\DateTimeImmutable $movedAt,
    ) {
    }

    /** The newest run blocked, gave up or waits for a person, and the card did not move since it ended. */
    public function needsPerson(): bool
    {
        if (!\in_array($this->state, [WorkerRunState::Blocked, WorkerRunState::GaveUp, WorkerRunState::WaitingForPerson], true)) {
            return false;
        }

        return null === $this->endedAt || null === $this->movedAt || $this->movedAt < $this->endedAt;
    }
}
