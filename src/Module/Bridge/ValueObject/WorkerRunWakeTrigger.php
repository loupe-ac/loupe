<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

/** Why a worker run resumed the session of an earlier run. */
enum WorkerRunWakeTrigger: string
{
    /** The owner closed an ask of the session. */
    case AskClosed = 'ask-closed';

    /** A person clicked an action on an inbox item. */
    case InboxAction = 'inbox-action';

    /** A person resumed the run. */
    case Person = 'person';

    /** An agent resumed the run through the MCP tool. */
    case Agent = 'agent';

    /** A work request resumed the session, such as a retry or a hand-back after a limit. */
    case WorkRequest = 'work-request';

    /** A report of a resume from before wakes were recorded. */
    case Backfill = 'backfill';
}
