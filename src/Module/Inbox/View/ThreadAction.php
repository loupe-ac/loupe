<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

/**
 * One row of the split button: the action, and the address when the action is a plain link.
 * An action with no address posts to Loupe.
 */
final readonly class ThreadAction
{
    public function __construct(
        public ItemAction $action,
        public ?string $href = null,
        /** The link leaves Loupe, as a pull request does. */
        public bool $external = false,
        /** The link opens a card, which the inbox page can show in its drawer. */
        public bool $card = false,
    ) {
    }

    public function isLink(): bool
    {
        return null !== $this->href;
    }
}
