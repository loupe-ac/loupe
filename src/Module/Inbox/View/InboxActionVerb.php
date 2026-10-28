<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

/** What the owner did to an item, as the status line says it. */
enum InboxActionVerb: string
{
    case Answered = 'answered';
    case Done = 'done';
    case Declined = 'declined';
    case Reviewed = 'reviewed';
    case Resumed = 'resumed';
    case Retried = 'retried';
    case Held = 'held';
    case AgentSent = 'agent-sent';
    case SessionStarted = 'session-started';
    case CommandQueued = 'command-queued';
    case EntryQueued = 'entry-queued';
    case Cancelled = 'cancelled';

    /** The flash that the card tab and the document page show instead of the status line. */
    public function flashKey(): string
    {
        return match ($this) {
            self::Answered => 'inbox.flash.answered',
            self::Done => 'inbox.flash.done',
            self::Declined => 'inbox.flash.declined',
            self::Reviewed => 'inbox.review.submitted',
            self::Resumed => 'inbox.flash.resumed',
            self::Retried => 'inbox.flash.retried',
            self::Held => 'inbox.flash.held',
            self::AgentSent => 'inbox.flash.agent_sent',
            self::SessionStarted => 'inbox.flash.session_started',
            self::CommandQueued => 'inbox.flash.command_queued',
            self::EntryQueued => 'inbox.flash.entry_queued',
            self::Cancelled => 'inbox.flash.cancelled',
        };
    }
}
