<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\InitInstallWaits;
use App\Module\Project\Service\ProjectSetupSkips;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/** Picks the page a return of the repository picker lands on. */
final readonly class PickerReturn
{
    public function __construct(
        private InitInstallWaits $installWaits,
        private ProjectSetupSkips $setupSkips,
        private RequestStack $requests,
        private ProjectRepository $projects,
    ) {
    }

    /**
     * The first rule that holds wins. A `loupe init` that waits for this install and a return that added a
     * repository go to the hand-off page. A project whose setup page applies goes to it. Any other return goes to
     * the tab the picker started from.
     *
     * @param ?string      $init        the init session the picker started for, when `loupe init` opened it
     * @param list<string> $added       the paths of the repositories this return added
     * @param ?string      $startedFrom `connect` when the picker started on the Connections tab
     */
    public function target(Project $project, ?string $init, array $added, ?string $startedFrom = null): PickerTarget
    {
        $id = (string) $project->id;
        if ([] !== $added && $this->installWaits->isWaiting($init, $id)) {
            return new PickerTarget('app_github_init_handoff', ['id' => $id, 'repository' => implode(',', $added), 'init' => (string) $init]);
        }

        $request = $this->requests->getCurrentRequest();
        if (null !== $request && $request->hasSession() && $this->setupSkips->gateApplies($request->getSession(), $project)) {
            return new PickerTarget('app_project_setup', ['id' => $id]);
        }

        return new PickerTarget('connect' === $startedFrom ? 'app_project_connect' : 'app_project_repositories_settings', ['id' => $id]);
    }

    /**
     * The same choice for a session that names the project by its id. A project that is gone leaves only the tab.
     *
     * @param list<string> $added
     */
    public function targetOfProjectId(string $projectId, ?string $init, array $added, ?string $startedFrom = null): PickerTarget
    {
        $project = Uuid::isValid($projectId) ? $this->projects->find(Uuid::fromString($projectId)) : null;

        return null === $project
            ? new PickerTarget('connect' === $startedFrom ? 'app_project_connect' : 'app_project_repositories_settings', ['id' => $projectId])
            : $this->target($project, $init, $added, $startedFrom);
    }
}
