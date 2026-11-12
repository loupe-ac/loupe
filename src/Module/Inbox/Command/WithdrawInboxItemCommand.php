<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Inbox\Entity\InboxItem;
use App\Security\ApiKeyActor;
use Symfony\Component\Uid\Uuid;

/**
 * The caller is an agent session or the public API. A session must be the one that asked first.
 * The API may take back any item the rules allow, and names its key when it came in on one.
 */
final readonly class WithdrawInboxItemCommand
{
    public function __construct(
        public InboxItem $item,
        /** Why the item is no longer needed. The handler refuses a blank one. */
        public string $reason,
        /** The agent session that withdraws. Null when the public API withdraws. */
        public ?Uuid $sessionId,
        /** The API key that withdraws. Null for an agent session, and for a sign-in that holds no key. */
        public ?ApiKeyActor $apiKey = null,
    ) {
    }
}
