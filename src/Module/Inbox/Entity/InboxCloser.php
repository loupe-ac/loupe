<?php

declare(strict_types=1);

namespace App\Module\Inbox\Entity;

use App\Module\Inbox\InboxEventType;

/** Who closed an inbox item. A response of the owner and a close of Loupe both have one. */
enum InboxCloser: string
{
    case Owner = 'owner';
    case OwnerThroughAgent = 'owner-through-agent';
    case Agent = 'agent';
    case Loupe = 'loupe';
    case Rule = 'rule';

    /**
     * The actor the bridge reads in the inbox.ask_closed event. The bridge
     * accepts four actors, so the closers share them.
     *
     * @return InboxEventType::ACTOR_*
     */
    public function outboxActor(): string
    {
        return match ($this) {
            self::Owner, self::OwnerThroughAgent => InboxEventType::ACTOR_HUMAN,
            self::Agent => InboxEventType::ACTOR_AGENT,
            self::Loupe, self::Rule => InboxEventType::ACTOR_SYSTEM,
        };
    }
}
