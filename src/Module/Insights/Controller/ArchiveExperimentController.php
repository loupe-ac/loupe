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
            foreach ($e->errors as $translationKey) {
                $this->addFlash('error', $this->translator->trans($translationKey));
            }

            return $this->redirectToRoute('app_project_analytics_experiments', ['id' => (string) $project->id]);
        }

        // The list row posts `from=list`, so the person stays on the list.
        if ('list' === $request->request->getString('from')) {
            return $this->redirectToRoute('app_project_analytics_experiments', ['id' => (string) $project->id]);
        }

        return $this->redirectToRoute('app_project_analytics_experiment', ['id' => (string) $project->id, 'experiment' => $experiment]);
    }
}
