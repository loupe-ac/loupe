<?php

declare(strict_types=1);

namespace App\Module\Inbox\EventListener;

use App\Module\Bridge\Event\BridgeCommandSettled;
use App\Module\Inbox\Repository\InboxActionRepository;
use App\Module\Inbox\Service\InboxActionRefresher;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** The bridge command of an action settled, so the status line of the action changed. */
#[AsEventListener]
final readonly class RefreshInboxOnBridgeCommandSettled
{
    public function __construct(
        private InboxActionRepository $inboxActions,
        private InboxActionRefresher $refresher,
    ) {
    }

    public function __invoke(BridgeCommandSettled $event): void
    {
        $this->refresher->refresh($this->inboxActions->findByBridgeCommandIds([$event->commandId]));
    }
}
