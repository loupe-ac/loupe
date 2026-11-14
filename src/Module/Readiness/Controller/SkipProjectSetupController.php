<?php

declare(strict_types=1);

namespace App\Module\Readiness\Controller;

use App\Controller\AppController;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Readiness\Command\SkipProjectSetupCommand;
use App\Module\Readiness\Command\SkipProjectSetupHandler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

/** Lets the owner open the project pages for the rest of the browser session. */
#[CsrfToken('project-setup-skip')]
#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/setup/skip',
    name: 'app_project_setup_skip',
    methods: ['POST'],
)]
final class SkipProjectSetupController extends AppController
{
    public function __construct(
        private readonly SkipProjectSetupHandler $skipSetup,
    ) {
    }

    public function __invoke(Project $project, Request $request): Response
    {
        $intended = ($this->skipSetup)(new SkipProjectSetupCommand($project, $request->getSession()));
        if (null !== $intended) {
            return $this->redirect($intended);
        }

        return $this->redirectToRoute('app_project_workshop', ['id' => (string) $project->id]);
    }
}
