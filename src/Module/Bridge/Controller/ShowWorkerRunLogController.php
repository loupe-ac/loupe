<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller;

use App\Controller\AppController;
use App\Module\Bridge\Command\ShowWorkerRunLogCommand;
use App\Module\Bridge\Command\ShowWorkerRunLogHandler;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/** The whole stored output log of a run as plain text, for a new tab. */
#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/worker-runs/{runId}/log',
    name: 'app_project_worker_run_log',
    requirements: ['runId' => Requirement::UUID],
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
final class ShowWorkerRunLogController extends AppController
{
    public function __construct(
        private readonly ShowWorkerRunLogHandler $showLog,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        Project $project,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(runId, project)')] WorkerRun $run,
    ): Response {
        $view = ($this->showLog)(new ShowWorkerRunLogCommand($run));
        $text = $view->text;
        if ($view->cut) {
            $text .= ('' === $text || str_ends_with($text, "\n") ? '' : "\n").$this->translator->trans('inbox.run_command.log_cut')."\n";
        }

        return new Response($text, Response::HTTP_OK, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
