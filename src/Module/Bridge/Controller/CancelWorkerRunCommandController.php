<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\CancelBridgeCommandCommand;
use App\Module\Bridge\Command\CancelBridgeCommandHandler;
use App\Module\Bridge\Command\ListWorkerRunsCommand;
use App\Module\Bridge\Command\ListWorkerRunsHandler;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\View\WorkerRunListQuery;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('worker-run-command')]
#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/worker-runs/{runId}/cancel-command',
    name: 'app_project_worker_run_cancel_command',
    requirements: ['runId' => Requirement::UUID],
    methods: ['POST'],
)]
final class CancelWorkerRunCommandController extends AppController
{
    public function __construct(
        private readonly CancelBridgeCommandHandler $cancelCommand,
        private readonly ListWorkerRunsHandler $listWorkerRuns,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        Request $request,
        Project $project,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(runId, project)')] WorkerRun $run,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('The project voter admits only a signed-in user.');
        }

        // The card frame reads a session that writes nothing, so a flash there would never clear.
        $cardId = $run->cardId();
        $fromCardFrame = null !== $cardId && 'card-worker-runs' === $request->headers->get('Turbo-Frame');
        $messages = [];
        try {
            ($this->cancelCommand)(new CancelBridgeCommandCommand($run, $user));
        } catch (DomainErrors $e) {
            $messages = array_values(array_map($this->translator->trans(...), $e->errors));
            if ($fromCardFrame) {
                return $this->render('@Bridge/_card_worker_runs.html.twig', [
                    'project' => $project,
                    'cardId' => (string) $cardId,
                    'commandErrors' => $messages,
                ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
            }
        }

        if ($fromCardFrame) {
            return $this->redirectToRoute('app_project_card_worker_runs', [
                'id' => (string) $project->id,
                'cardId' => (string) $cardId,
            ], Response::HTTP_SEE_OTHER);
        }

        $listQuery = WorkerRunListQuery::fromQuery($request->query);
        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $view = ($this->listWorkerRuns)(new ListWorkerRunsCommand($project, $listQuery, clampPage: true));
            $body = $this->renderView('@Bridge/_worker_runs.stream.html.twig', [
                'project' => $project,
                'view' => $view,
                'listQuery' => $listQuery->withPage($view->page),
            ]);
            foreach ($messages as $message) {
                $body .= $this->renderView('_flash.stream.html.twig', ['severity' => 'error', 'message' => $message]);
            }

            return new Response($body, Response::HTTP_OK, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
        }

        foreach ($messages as $message) {
            $this->addFlash('worker-run-command', $message);
        }

        return $this->redirectToRoute('app_project_worker_runs', ['id' => (string) $project->id] + $listQuery->routeParams(), Response::HTTP_SEE_OTHER);
    }
}
