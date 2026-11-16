<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

/** What a forge says about one branch. A forge that cannot answer says Unreadable, never Absent. */
enum BranchPresence
{
    case Exists;
    case Absent;
    case Unreadable;
}
