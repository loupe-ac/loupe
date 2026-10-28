<?php

declare(strict_types=1);

namespace App\Module\Forge\Messenger;

/** Asks for one check of one repository. */
final readonly class CheckForgeRepository
{
    public function __construct(
        public string $repositoryId,
        public CheckTrigger $trigger,
    ) {
    }
}
