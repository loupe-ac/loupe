<?php

declare(strict_types=1);

namespace App\Module\Forge\Messenger;

/** What asked for a repository check. */
enum CheckTrigger: string
{
    case Hourly = 'hourly';
    case PageOpen = 'page-open';
    case CheckAgain = 'check-again';
}
