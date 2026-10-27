<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Module\GitHub\Service\GitHubRepositoryRows;
use App\Module\GitHub\View\RepositoryRow;

/** Reads one row of the Repositories section from its stored check. It queues no check, so the row never says "Checking…". */
final readonly class ShowRepositoryRowHandler
{
    public function __construct(
        private GitHubRepositoryRows $rows,
    ) {
    }

    public function __invoke(ShowRepositoryRowCommand $command): RepositoryRow
    {
        return $this->rows->forRepository($command->repository, requestCheck: false);
    }
}
