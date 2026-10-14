<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

use App\Module\Inbox\Entity\InboxActionKind;

/** One action that fits an inbox item now. The keys are translation keys. */
final readonly class ItemAction
{
    public function __construct(
        public InboxActionKind $kind,
        public string $labelKey,
        public string $effectKey,
        public bool $default = false,
        public ?string $disabledReasonKey = null,
        /** The one-line reason of the worker that suggested the action, as plain text. */
        public ?string $reason = null,
    ) {
    }

    public static function for(InboxActionKind $kind, bool $default = false, ?string $reason = null): self
    {
        return new self($kind, \sprintf('inbox.action.%s.label', $kind->value), \sprintf('inbox.action.%s.effect', $kind->value), $default, reason: $reason);
    }
}
