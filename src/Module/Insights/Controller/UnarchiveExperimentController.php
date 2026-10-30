<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller;

use App\Controller\AppController;
use App\Module\Bridge\Command\UnarchiveExperimentCommand;
use App\Module\Bridge\Command\UnarchiveExperimentHandler;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('experiment-unarchive')]
#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/analytics/experiments/{experiment}/unarchive',
    name: 'app_project_analytics_experiment_unarchive',
    requirements: ['experiment' => WorkerRun::EXPERIMENT_NAME],
    methods: ['POST'],
)]
class UnarchiveExperimentController extends AppController
{
    public function __construct(
        private readonly UnarchiveExperimentHandler $unarchiveExperiment,
    ) {
    }

    public function __invoke(Project $project, string $experiment, Request $request): Response
    {
        ($this->unarchiveExperiment)(new UnarchiveExperimentCommand($project, $experiment));

        // The archived list row posts `from=list`, so the person stays on the list.
        if ('list' === $request->request->getString('from')) {
            return $this->redirectToRoute('app_project_analytics_experiments', array_filter([
                'id' => (string) $project->id,
                'archived' => $request->request->getBoolean('archived') ? 1 : null,
            ]));
        }

        return $this->redirectToRoute('app_project_analytics_experiment', ['id' => (string) $project->id, 'experiment' => $experiment]);
    }
}
