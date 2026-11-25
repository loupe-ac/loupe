<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** Why the engine ends the wait of a worker. */
enum WaitEnd: string
{
    /** One of the conditions of the wait turned true. */
    case Condition = 'condition';

    /** The time limit passed. */
    case TimedOut = 'timed-out';

    /** The card no longer links the pull request that a condition reads, or the pull request is no longer open. */
    case SubjectGone = 'subject-gone';
}
