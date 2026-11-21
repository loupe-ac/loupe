<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller;

use App\Controller\AppController;
use App\Module\Bridge\Command\ListWorkerRunsCommand;
use App\Module\Bridge\Command\ListWorkerRunsHandler;
use App\Module\Bridge\View\WorkerRunListQuery;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\Turbo\TurboBundle;

/** The live read of the Runs list: the list frame and the shown count, in one stream. */
#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/worker-runs/live',
    name: 'app_project_worker_runs_live',
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
final class ListWorkerRunsLiveController extends AppController
{
    public function __construct(
        private readonly ListWorkerRunsHandler $listWorkerRuns,
    ) {
    }

    public function __invoke(Project $project, Request $request): Response
    {
        $listQuery = WorkerRunListQuery::fromQuery($request->query);
        $view = ($this->listWorkerRuns)(new ListWorkerRunsCommand($project, $listQuery, clampPage: true));

        return new Response(
            $this->renderView('@Bridge/_worker_runs.stream.html.twig', [
                'project' => $project,
                'view' => $view,
                'listQuery' => $listQuery->withPage($view->page),
            ]),
            Response::HTTP_OK,
            ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE],
        );
    }
}
