<?php

declare(strict_types=1);

namespace App\Module\GitHub\View;

/** How a repository of the picker stands for the project that opens it. */
enum GitHubPickerState: string
{
    case Free = 'free';
    case InProject = 'in-project';
    case OtherProject = 'other-project';
}
