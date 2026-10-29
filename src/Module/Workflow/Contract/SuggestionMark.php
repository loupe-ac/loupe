<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** How serious a gap is: a blocking gap can hold new work, and a warning never does. */
enum SuggestionMark: string
{
    case Blocking = 'blocking';
    case Warning = 'warning';
}
