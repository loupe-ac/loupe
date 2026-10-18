<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

/** What the Git identity panel says about one machine. The value picks the translation keys and the chip. */
enum GitIdentityState: string
{
    case AppBot = 'app-bot';
    case AgentAccount = 'agent-account';
    case NotManaged = 'not-managed';
    case Detected = 'detected';
    case OwnerAccount = 'owner-account';
    case MaybeOwnerAccount = 'maybe-owner-account';
    case Unknown = 'unknown';

    /** The Ds:StatusChip modifier. */
    public function chipModifier(): string
    {
        return match ($this) {
            self::AppBot, self::AgentAccount => 'ok',
            self::Detected => 'active',
            self::OwnerAccount, self::MaybeOwnerAccount => 'pending',
            self::NotManaged, self::Unknown => 'neutral',
        };
    }

    /** The translation key of the chip label. */
    public function chipLabelKey(): string
    {
        return 'github.identity.chip.'.match ($this) {
            self::AppBot, self::AgentAccount => 'managed',
            self::NotManaged => 'not_managed',
            self::Detected => 'detected',
            self::OwnerAccount, self::MaybeOwnerAccount => 'check',
            self::Unknown => 'unknown',
        };
    }

    /** Whether the panel adds a warning under the line. */
    public function warns(): bool
    {
        return self::OwnerAccount === $this || self::MaybeOwnerAccount === $this;
    }
}
