<?php

declare(strict_types=1);

namespace App\Module\Inbox\Scheduler;

use App\Module\Inbox\Command\ReconcileBridgeOutagesCommand;
use App\Module\Inbox\Command\ReconcileBridgeOutagesHandler;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/** Opens and closes the notice that says no bridge can take the work of a project. */
#[AsCronTask('%app.inbox.bridge_outage_schedule%')]
final readonly class ReconcileBridgeOutagesTask
{
    public function __construct(
        private ReconcileBridgeOutagesHandler $reconcileBridgeOutages,
    ) {
    }

    public function __invoke(): void
    {
        ($this->reconcileBridgeOutages)(new ReconcileBridgeOutagesCommand());
    }
}
