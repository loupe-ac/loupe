<?php

declare(strict_types=1);

namespace App\Module\Inbox\EventListener;

use App\Module\Board\Event\BoardColumnTerminalChanged;
use App\Module\Inbox\Command\MarkInboxItemsObsoleteCommand;
use App\Module\Inbox\Command\MarkInboxItemsObsoleteHandler;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A column that turns terminal finishes its cards with no CardMoved, so they reach the obsolete rule from here. */
#[AsEventListener]
final readonly class MarkInboxItemsObsoleteOnBoardColumnTerminalChanged
{
    public function __construct(
        private MarkInboxItemsObsoleteHandler $markObsolete,
    ) {
    }

    public function __invoke(BoardColumnTerminalChanged $event): void
    {
        if (!$event->terminal) {
            return;
        }

        ($this->markObsolete)(new MarkInboxItemsObsoleteCommand($event->cardIds));
    }
}
