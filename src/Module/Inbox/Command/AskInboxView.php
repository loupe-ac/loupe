<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxItem;

/**
 * The ask the items went to, the items this call created in the order given, and the asks that the template logged.
 * A call whose items are all logged has no ask.
 */
final readonly class AskInboxView
{
    /**
     * @param list<InboxItem>      $items
     * @param list<AskInboxLogged> $logged
     */
    public function __construct(
        public ?InboxAsk $ask,
        public array $items,
        public bool $extended,
        public array $logged = [],
    ) {
    }
}
