<?php

declare(strict_types=1);

namespace App\Outbox\Command;

use App\Module\Account\Entity\User;

final readonly class SeedActivityEventsCommand
{
    public function __construct(
        public User $owner,
        public string $projectName,
        public int $published,
        public int $unsent,
    ) {
    }
}
