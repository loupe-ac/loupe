<?php

declare(strict_types=1);

namespace App\Module\Readiness\Controller;

use App\Controller\AppController;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Readiness\Command\ShowProjectSetupCommand;
use App\Module\Readiness\Command\ShowProjectSetupHandler;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The frame of the setup page, which the page reloads when the setup changes. */
#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/setup/list',
    name: 'app_project_setup_list',
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
final class ListProjectSetupController extends AppController
{
    public function __construct(
        private readonly ShowProjectSetupHandler $showSetup,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        $view = ($this->showSetup)(new ShowProjectSetupCommand($project, $this->isGranted(ProjectVoter::MANAGE, $project)));

        return $this->render('@Readiness/setup/_project_setup_card.html.twig', ['setup' => $view]);
    }
}
