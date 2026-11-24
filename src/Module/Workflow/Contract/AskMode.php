<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** What the inbox does with a labelled ask: log the choice, ask with a deadline, or ask. */
enum AskMode: string
{
    case Log = 'log';
    case Default = 'default';
    case Ask = 'ask';
}
