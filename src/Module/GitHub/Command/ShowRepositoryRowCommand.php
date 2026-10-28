<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Module\Forge\Entity\ForgeRepository;

final readonly class ShowRepositoryRowCommand
{
    public function __construct(
        public ForgeRepository $repository,
    ) {
    }
}
