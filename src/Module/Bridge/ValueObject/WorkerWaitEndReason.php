<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

use App\Module\Workflow\Contract\WaitEnd;

/** Why a wait of a worker ended. */
enum WorkerWaitEndReason: string
{
    /** One of the conditions of the wait turned true. */
    case Condition = 'condition';

    /** The time limit passed. */
    case TimedOut = 'timed-out';

    /** The card no longer links the pull request that a condition reads, or the pull request is no longer open. */
    case SubjectGone = 'subject-gone';

    case ResumedByPerson = 'resumed-by-person';

    case CancelledByPerson = 'cancelled-by-person';

    case CardMoved = 'card-moved';

    case CardFinished = 'card-finished';

    case CardHeld = 'card-held';

    /** The run ended finished or blocked, so the worker no longer waits for anything. */
    case RunFinished = 'run-finished';

    public static function fromEnd(WaitEnd $end): self
    {
        return match ($end) {
            WaitEnd::Condition => self::Condition,
            WaitEnd::TimedOut => self::TimedOut,
            WaitEnd::SubjectGone => self::SubjectGone,
        };
    }

    /** The ends after which Loupe resumes the session of the wait. The other ends stop the run, or finish its work. */
    public function resumesSession(): bool
    {
        return \in_array($this, self::resumingReasons(), true);
    }

    /** The ends that a person or a change of the card causes, which the card history records. */
    public function isOwnerOrCardChange(): bool
    {
        return self::ResumedByPerson === $this || $this->stopsRun();
    }

    /** The ends that stop a run that has not reported its end yet. */
    public function stopsRun(): bool
    {
        return \in_array($this, self::stoppingReasons(), true);
    }

    /** @return list<self> */
    public static function stoppingReasons(): array
    {
        return [self::CancelledByPerson, self::CardMoved, self::CardFinished, self::CardHeld];
    }

    /** @return list<self> */
    public static function resumingReasons(): array
    {
        return [self::Condition, self::TimedOut, self::SubjectGone, self::ResumedByPerson];
    }
}
