<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

/** What a successful command run does to the item that asked for it. */
enum RunCommandOutcome: string
{
    case ClosesTodo = 'closes-todo';
    case ResumesRun = 'resumes-run';
}
