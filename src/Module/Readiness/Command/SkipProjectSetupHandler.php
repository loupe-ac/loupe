<?php

declare(strict_types=1);

namespace App\Module\Readiness\Command;

use App\Module\Project\Service\ProjectSetupSkips;
use App\Module\Readiness\EventListener\RequireProjectSetUpListener;

/** Adds the project to the skip list of the session and reads the address the gate saved. */
final readonly class SkipProjectSetupHandler
{
    public function __construct(
        private ProjectSetupSkips $skips,
    ) {
    }

    /** @return ?string the saved address when it belongs to this project, otherwise null */
    public function __invoke(SkipProjectSetupCommand $command): ?string
    {
        $this->skips->skip($command->session, $command->project);

        $intended = $command->session->remove(RequireProjectSetUpListener::INTENDED_PATH_SESSION_KEY);

        return \is_string($intended) && str_starts_with($intended, '/projects/'.$command->project->id.'/') ? $intended : null;
    }
}
