<?php

declare(strict_types=1);

namespace App\Module\Inbox\EventListener;

use App\Module\Bridge\Event\WorkerRunChanged;
use App\Module\Inbox\Repository\InboxActionRepository;
use App\Module\Inbox\Service\InboxActionRefresher;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A run that an action resumed, or the run it continues, changed, so the status line of the action changed too. */
#[AsEventListener]
final readonly class RefreshInboxOnWorkerRunChanged
{
    public function __construct(
        private InboxActionRepository $inboxActions,
        private InboxActionRefresher $refresher,
    ) {
    }

    public function __invoke(WorkerRunChanged $event): void
    {
        $this->refresher->refresh($this->inboxActions->findByRunIds($event->runIds));
    }
}
