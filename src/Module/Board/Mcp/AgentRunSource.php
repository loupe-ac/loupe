<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use App\Module\Board\Entity\CardSource;
use App\Module\Bridge\Service\CallerRun;
use App\Module\Project\Entity\Project;

/** The worker run that created a card through the MCP, read from the run credential of the call. */
final readonly class AgentRunSource
{
    public function __construct(
        private CallerRun $callerRun,
    ) {
    }

    /** Null when the call holds no run credential, or its run is not reported in the project. */
    public function forProject(Project $project): ?CardSource
    {
        $run = $this->callerRun->workerRun($project);
        if (null === $run) {
            return null;
        }

        return CardSource::run($run->id ?? throw new \LogicException('A persisted run has an id.'), $run->cardId());
    }
}
