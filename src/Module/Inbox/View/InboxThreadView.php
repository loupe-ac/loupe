<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Inbox\Entity\InboxCardWatch;
use App\Module\Inbox\Entity\InboxItem;

/**
 * One item as a thread: the request, the actions the owner ran, the response or the closing message,
 * and either the reply card or nothing. A sent response is final, so a closed item has no reply card.
 */
final readonly class InboxThreadView
{
    /**
     * @param ?string                 $body       the agent's text, or the text of a notice. Null for a wait item, which shows its waits
     * @param list<InboxCardWait>     $waits      the waits of a wait item, in page order
     * @param list<InboxActionStatus> $actions    the actions the owner ran, oldest first
     * @param ?string                 $refusalKey what a late response gets, set once the item is closed
     */
    public function __construct(
        public InboxItem $item,
        public ?string $body,
        public ?InboxCardWatch $watch,
        public array $waits,
        public array $actions,
        public ?InboxThreadReply $reply,
        public ?InboxThreadClosing $closing,
        public ?ReplyCard $replyCard,
        public ?string $refusalKey,
    ) {
    }
}
