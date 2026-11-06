<?php

declare(strict_types=1);

namespace App\Module\Inbox\Messenger;

/** What the id of a NotifyInboxOwner message names. */
enum NotifyInboxOwnerKind: string
{
    case Ask = 'ask';
    /** The id names an item: a notice, or an item from the public API, whose ask closes at once. */
    case Notice = 'notice';
}
