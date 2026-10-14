<?php

declare(strict_types=1);

namespace App\Module\Inbox\EventListener;

use App\Module\Bridge\Event\WorkRequestChanged;
use App\Module\Inbox\Repository\InboxActionRepository;
use App\Module\Inbox\Service\InboxActionRefresher;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** The work request of an action opened, was claimed, settled or withdrew, so the status line of the action changed. */
#[AsEventListener]
final readonly class RefreshInboxOnWorkRequestChanged
{
    public function __construct(
        private InboxActionRepository $inboxActions,
        private InboxActionRefresher $refresher,
    ) {
    }

    public function __invoke(WorkRequestChanged $event): void
    {
        $this->refresher->refresh($this->inboxActions->findByWorkRequestIds([$event->workRequestId]));
    }
}
