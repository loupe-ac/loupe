<?php

declare(strict_types=1);

namespace App\Module\GitHub;

/** How one repository path stands for a project, as the lookup route answers it. */
enum GitHubRepositoryStanding: string
{
    case InProject = 'in-project';
    case Free = 'free';
    case OtherProject = 'other-project';
    case NotShared = 'not-shared';
}
