<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Workflow\Contract\ProjectTemplateKeys;

final readonly class ShowInteractiveSessionCardRunHandler
{
    public function __construct(
        private ProjectRepository $projects,
        private WorkerRunRepository $workerRuns,
        private ProjectTemplateKeys $templateKeys,
    ) {
    }

    /** @return ?ShowInteractiveSessionCardRunView null when the owner has no such project, whether it exists for another account or not */
    public function __invoke(ShowInteractiveSessionCardRunCommand $command): ?ShowInteractiveSessionCardRunView
    {
        $project = $this->projects->findOneByIdOrSlugForOwner($command->projectHandle, $command->owner);
        if (null === $project) {
            return null;
        }

        return new ShowInteractiveSessionCardRunView(
            $this->templateKeys->of($project->requireId()),
            $this->workerRuns->hasOpenInteractiveOfSession($project, $command->sessionId),
        );
    }
}
