<?php

declare(strict_types=1);

namespace App\Module\Readiness\Controller;

use App\Controller\AppController;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Readiness\Command\ShowProjectSetupCommand;
use App\Module\Readiness\Command\ShowProjectSetupHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/setup',
    name: 'app_project_setup',
    methods: ['GET'],
)]
final class ShowProjectSetupController extends AppController
{
    public function __construct(
        private readonly ShowProjectSetupHandler $showSetup,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        $view = ($this->showSetup)(new ShowProjectSetupCommand($project, $this->isGranted(ProjectVoter::MANAGE, $project)));

        return $this->render('@Readiness/setup/show_project_setup.html.twig', ['setup' => $view]);
    }
}
