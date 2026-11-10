<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

final readonly class BackfilledInstallationAccounts
{
    public function __construct(
        public int $filled,
        public int $unlisted,
    ) {
    }
}
