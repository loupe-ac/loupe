<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Bridge\Command\ArchiveExperimentCommand;
use App\Module\Bridge\Command\ArchiveExperimentHandler;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('experiment-archive')]
#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/analytics/experiments/{experiment}/archive',
    name: 'app_project_analytics_experiment_archive',
    requirements: ['experiment' => WorkerRun::EXPERIMENT_NAME],
    methods: ['POST'],
)]
class ArchiveExperimentController extends AppController
{
    public function __construct(
        private readonly ArchiveExperimentHandler $archiveExperiment,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Project $project, string $experiment, Request $request): Response
    {
        try {
            ($this->archiveExperiment)(new ArchiveExperimentCommand($project, $experiment));
        } catch (DomainErrors $e) {
            $listParams = array_filter([
                'id' => (string) $project->id,
                'archived' => $request->request->getBoolean('archived') ? 1 : null,
            ]);

            // The row form posts inside the list frame, and the session flash renders outside it, so the answer carries its own flash and reloads the frame.
            if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat() && 'experiments-list' === $request->headers->get('Turbo-Frame')) {
                return new Response(
                    $this->renderView('@Insights/_experiment_archive_refused.stream.html.twig', [
                        'messages' => array_map($this->translator->trans(...), $e->errors),
                        'listUrl' => $this->generateUrl('app_project_analytics_experiments', $listParams),
                    ]),
                    Response::HTTP_OK,
                    ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE],
                );
            }

            foreach ($e->errors as $translationKey) {
                $this->addFlash('error', $this->translator->trans($translationKey));
            }

            return $this->redirectToRoute('app_project_analytics_experiments', $listParams);
        }

        // The list row posts `from=list`, so the person stays on the list, with the archived view it came from.
        if ('list' === $request->request->getString('from')) {
            return $this->redirectToRoute('app_project_analytics_experiments', array_filter([
                'id' => (string) $project->id,
                'archived' => $request->request->getBoolean('archived') ? 1 : null,
            ]));
        }

        return $this->redirectToRoute('app_project_analytics_experiment', ['id' => (string) $project->id, 'experiment' => $experiment]);
    }
}
