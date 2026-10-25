<?php

declare(strict_types=1);

namespace App\Module\Notification\Command;

use App\Module\Notification\Service\PresenceStore;

/** Notes that the user is using Loupe now, so a push would only repeat the page. */
final readonly class RecordPresenceHandler
{
    public function __construct(
        private PresenceStore $presence,
    ) {
    }

    public function __invoke(RecordPresenceCommand $command): void
    {
        $this->presence->record($command->user);
    }
}
