<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller;

use App\Controller\AppController;
use App\Module\Bridge\Command\ShowWorkerRunOutputCommand;
use App\Module\Bridge\Command\ShowWorkerRunOutputHandler;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The new text of the live output log of a run, from a byte offset on. A page
 * calls it again until the text comes back empty.
 */
#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/worker-runs/{runId}/output',
    name: 'app_project_worker_run_output',
    requirements: ['runId' => Requirement::UUID],
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
final class ShowWorkerRunOutputController extends AppController
{
    public function __construct(
        private readonly ShowWorkerRunOutputHandler $showOutput,
    ) {
    }

    public function __invoke(
        Request $request,
        Project $project,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(runId, project)')] WorkerRun $run,
    ): JsonResponse {
        $view = ($this->showOutput)(new ShowWorkerRunOutputCommand($run, $request->query->getInt('from')));

        $response = $this->json(['text' => $view->text, 'length' => $view->length, 'cut' => $view->cut]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
