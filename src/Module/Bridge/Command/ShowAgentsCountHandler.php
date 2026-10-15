<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Service\AgentsFigures;
use App\Module\Bridge\Service\AgentsFiguresReader;

/** The busy and maximum workers of a project, for the sidebar pill of the Agents link. */
final readonly class ShowAgentsCountHandler
{
    public function __construct(
        private AgentsFiguresReader $figures,
    ) {
    }

    public function __invoke(ShowAgentsCountCommand $command): ?AgentsFigures
    {
        return $this->figures->forProject($command->project);
    }
}
