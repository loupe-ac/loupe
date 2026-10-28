<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Module\Forge\Entity\ForgeRepository;

final readonly class DownloadRepositoryRulesetCommand
{
    public function __construct(
        public ForgeRepository $repository,
    ) {
    }
}
