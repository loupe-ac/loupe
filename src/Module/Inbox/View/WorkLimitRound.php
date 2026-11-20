<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\ValueObject\WorkerRunState;

/** One round of a rule on a card: the request that the work limit counted and the runs that answered it. */
final readonly class WorkLimitRound
{
    public WorkLimitRoundOutcome $outcome;

    /** A run before the last one stopped to ask a person, and a later run went on. */
    public bool $askedFirst;

    /** @param list<WorkerRun> $runs oldest first */
    public function __construct(
        public WorkRequest $request,
        public array $runs,
    ) {
        $last = [] === $runs ? null : array_last($runs);
        $this->outcome = WorkLimitRoundOutcome::of($last?->state);
        $this->askedFirst = null !== $last && array_any(
            \array_slice($runs, 0, -1),
            static fn (WorkerRun $run): bool => \in_array($run->state, [WorkerRunState::Blocked, WorkerRunState::WaitingForPerson], true),
        );
    }

    public function lastRun(): ?WorkerRun
    {
        return [] === $this->runs ? null : array_last($this->runs);
    }
}
