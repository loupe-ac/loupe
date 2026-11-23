<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

final readonly class CloseDefaultedInboxItemsCommand
{
    public function __construct(
        public \DateTimeImmutable $now = new \DateTimeImmutable(),
    ) {
    }
}
