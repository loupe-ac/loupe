<?php

declare(strict_types=1);

namespace App\Module\Inbox\Scheduler;

use App\Module\Inbox\Command\CloseDefaultedInboxItemsCommand;
use App\Module\Inbox\Command\CloseDefaultedInboxItemsHandler;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/** Answers each open ask whose deadline passed with the recommended option of the agent. */
#[AsCronTask('%app.inbox.default_close_schedule%')]
final readonly class CloseDefaultedInboxItemsTask
{
    public function __construct(
        private CloseDefaultedInboxItemsHandler $closeDefaulted,
    ) {
    }

    public function __invoke(): void
    {
        ($this->closeDefaulted)(new CloseDefaultedInboxItemsCommand());
    }
}
