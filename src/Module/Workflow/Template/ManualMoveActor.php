<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

/** Who may make a manual move, when a person may not. */
enum ManualMoveActor: string
{
    case ParentRun = 'parent-run';

    /** An update that opens an interactive session run in the same call. */
    case Session = 'session';
}
