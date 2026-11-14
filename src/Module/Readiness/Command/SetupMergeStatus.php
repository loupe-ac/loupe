<?php

declare(strict_types=1);

namespace App\Module\Readiness\Command;

/** Who approves a merge in one repository, as far as Loupe knows. It never holds the setup. */
enum SetupMergeStatus: string
{
    case NotAsked = 'not-asked';
    case InInitPullRequest = 'in-init-pull-request';
    case Person = 'person';
    case Agent = 'agent';
}
