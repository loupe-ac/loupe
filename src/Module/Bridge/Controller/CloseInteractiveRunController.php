<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller;

use App\Controller\AppController;
use App\Module\Bridge\Command\CloseInteractiveRunCommand;
use App\Module\Bridge\Command\CloseInteractiveRunHandler;
use App\Module\Bridge\Command\ListWorkerRunsCommand;
use App\Module\Bridge\Command\ListWorkerRunsHandler;
use App\Module\Bridge\View\WorkerRunListQuery;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Symfony\UX\Turbo\TurboBundle;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

/** The answer lands in the place that sent the close: the card runs frame, or the Runs list the reader had. */
#[CsrfToken('worker-run-command')]
#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/worker-runs/{runId}/close',
    name: 'app_project_worker_run_close',
    requirements: ['runId' => Requirement::UUID],
    methods: ['POST'],
)]
class CloseInteractiveRunController extends AppController
{
    public function __construct(
        private readonly CloseInteractiveRunHandler $closeInteractiveRun,
        private readonly ListWorkerRunsHandler $listWorkerRuns,
    ) {
    }

    public function __invoke(Request $request, Project $project, string $runId): Response
    {
        $run = ($this->closeInteractiveRun)(new CloseInteractiveRunCommand($project, Uuid::fromString($runId)))
            ?? throw $this->createNotFoundException();

        $frame = $request->headers->get('Turbo-Frame');
        if ('card-worker-runs' === $frame) {
            return $this->redirectToRoute('app_project_card_worker_runs', [
                'id' => (string) $project->id,
                'cardId' => (string) $run->subjectId,
            ], Response::HTTP_SEE_OTHER);
        }

        $listQuery = WorkerRunListQuery::fromQuery($request->query);
        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
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

        return $this->redirectToRoute('app_project_worker_runs', ['id' => (string) $project->id] + $listQuery->routeParams(), Response::HTTP_SEE_OTHER);
    }
}
