<?php

declare(strict_types=1);

namespace App\Module\Readiness\Command;

use App\Module\Project\Entity\Project;

final readonly class ProjectSetupView
{
    /**
     * @param list<string>         $repositories the paths of the repositories that joined the project
     * @param list<SetupMergeLine> $mergeLines   one line for each repository, empty in the states that show no list
     */
    public function __construct(
        public Project $project,
        public ProjectSetupState $state,
        public array $repositories,
        public bool $initRunning,
        public bool $initFinished,
        public bool $bridgeConnected,
        public array $mergeLines,
    ) {
    }
}
