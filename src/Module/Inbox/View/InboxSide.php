<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

enum InboxSide: string
{
    case Open = 'open';
    case Closed = 'closed';
}
