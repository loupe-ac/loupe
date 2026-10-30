<?php

declare(strict_types=1);

namespace App\Module\Board\Entity;

/** What happened to a card, as one row of its history records it. */
enum CardEventKind: string
{
    case Created = 'created';
    case Moved = 'moved';
    case FixRequested = 'fix-requested';
    case Stopped = 'stopped';
    case ReadyToMerge = 'ready-to-merge';
    case Synced = 'synced';
    case RunFinished = 'run-finished';
    case Paused = 'paused';
    case PauseReleased = 'pause-released';
    case Verdict = 'verdict';
    case ReviewOverridden = 'review-overridden';
    case ReviewOverrideEnded = 'override-ended';
    case ResumeSkipped = 'resume-skipped';
    case DocumentVerdict = 'document-verdict';
    case VerdictFailed = 'verdict-failed';
    case AutoApproved = 'auto-approved';
    case ApprovalDismissed = 'approval-dismissed';
    case HumanReviewChanged = 'human-review-changed';
    case ValueChanged = 'value-changed';
}
