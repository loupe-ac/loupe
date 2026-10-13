<?php

declare(strict_types=1);

namespace App\Module\Inbox\EventListener;

use App\Module\Board\Event\CardChanged;
use App\Module\Inbox\Service\InboxSearchIndexer;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** The search vector of an inbox item holds the titles of its linked cards. */
#[AsEventListener]
final readonly class ReindexInboxItemsOnCardChanged
{
    public function __construct(
        private InboxSearchIndexer $searchIndexer,
    ) {
    }

    public function __invoke(CardChanged $event): void
    {
        if (CardChanged::UPDATED !== $event->change || !$event->contentChanged) {
            return;
        }

        $this->searchIndexer->reindexForCard($event->cardId);
    }
}
