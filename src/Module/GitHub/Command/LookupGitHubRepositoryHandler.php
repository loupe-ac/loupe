<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Module\GitHub\GitHubRepositoryStanding;
use App\Module\GitHub\Service\GitHubAppApiFailed;
use App\Module\GitHub\Service\GitHubRepositoryStandings;

final readonly class LookupGitHubRepositoryHandler
{
    public function __construct(
        private GitHubRepositoryStandings $standings,
    ) {
    }

    /** @throws GitHubAppApiFailed */
    public function __invoke(LookupGitHubRepositoryCommand $command): GitHubRepositoryStanding
    {
        return $this->standings->of($command->project, $command->path);
    }
}
