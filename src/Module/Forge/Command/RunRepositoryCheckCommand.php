<?php

declare(strict_types=1);

namespace App\Module\Forge\Command;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Messenger\CheckTrigger;

final readonly class RunRepositoryCheckCommand
{
    public function __construct(
        public ForgeRepository $repository,
        public CheckTrigger $trigger,
    ) {
    }
}
