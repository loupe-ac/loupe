<?php

declare(strict_types=1);

namespace App\Module\Inbox\Messenger;

/** What the id of a NotifyInboxOwner message names. */
enum NotifyInboxOwnerKind: string
{
    case Ask = 'ask';
    case Notice = 'notice';
}
