<?php

declare(strict_types=1);

namespace App\Module\Readiness\Command;

/** The text the setup page shows, in the order the handler tests them. */
enum ProjectSetupState: string
{
    case Done = 'done';
    case Member = 'member';
    case InitRunning = 'init-running';
    case BridgePending = 'bridge-pending';
    case InitStopped = 'init-stopped';
    case RepositoriesJoined = 'repositories-joined';
    case NoRepository = 'no-repository';
}
