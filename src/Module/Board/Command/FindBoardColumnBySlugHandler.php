<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Repository\BoardColumnRepository;

final readonly class FindBoardColumnBySlugHandler
{
    public function __construct(
        private BoardColumnRepository $boardColumns,
    ) {
    }

    public function __invoke(FindBoardColumnBySlugCommand $command): ?BoardColumn
    {
        return $this->boardColumns->findOneByProjectAndSlug($command->project, $command->slug);
    }
}
