<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

use App\Module\Bridge\ValueObject\WorkerRunState;

/** How a round of a rule ended, in the words of a person and not the end state of a run. */
enum WorkLimitRoundOutcome: string
{
    /** The worker handed the pull request to the checks and the review. */
    case HandedBack = 'handed-back';
    case WaitingForYou = 'waiting-for-you';
    case DidNotFinish = 'did-not-finish';
    case Failed = 'failed';
    case StoppedByPerson = 'stopped-by-person';
    case NoRunYet = 'no-run-yet';
    case Other = 'other';

    public static function of(?WorkerRunState $last): self
    {
        return match ($last) {
            null => self::NoRunYet,
            WorkerRunState::Waiting, WorkerRunState::Succeeded => self::HandedBack,
            WorkerRunState::Blocked, WorkerRunState::WaitingForPerson => self::WaitingForYou,
            WorkerRunState::Unfinished, WorkerRunState::GaveUp, WorkerRunState::NoResult => self::DidNotFinish,
            WorkerRunState::Failed, WorkerRunState::NotStarted, WorkerRunState::TimedOut, WorkerRunState::Lost => self::Failed,
            WorkerRunState::Stopped => self::StoppedByPerson,
            default => self::Other,
        };
    }

    public function translationKey(): string
    {
        return 'marker.paused.work_limit.round.'.str_replace('-', '_', $this->value);
    }
}
