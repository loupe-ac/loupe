<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

/** The split button of one item: the main part, the menu under the chevron, and Dismiss apart at the bottom. */
final readonly class InboxThreadActions
{
    /** @param list<ThreadAction> $menu the actions that fit, without the main one and without Dismiss */
    public function __construct(
        public ?ThreadAction $main,
        public array $menu,
        public ?ThreadAction $dismiss,
    ) {
    }

    public function isEmpty(): bool
    {
        return null === $this->main && [] === $this->menu && null === $this->dismiss;
    }
}
