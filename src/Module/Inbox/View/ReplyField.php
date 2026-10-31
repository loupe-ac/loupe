<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

/** A field of the reply card that the owner fills before the main action. */
enum ReplyField: string
{
    case Options = 'options';
    case Verdict = 'verdict';
    case Note = 'note';
}
