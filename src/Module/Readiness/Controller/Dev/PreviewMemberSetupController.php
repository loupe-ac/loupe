<?php

declare(strict_types=1);

namespace App\Module\Readiness\Controller\Dev;

use App\Controller\AppController;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Readiness\Command\ProjectSetupState;
use App\Module\Readiness\Command\ProjectSetupView;
use App\Module\Readiness\Command\ShowProjectSetupCommand;
use App\Module\Readiness\Command\ShowProjectSetupHandler;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Dev-only setup page in its member state, for a signed preview link.
 * No account can view a project without managing it, so the real route never shows it.
 */
#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/dev/project-setup-preview/{id:project}/member',
    name: 'dev_project_setup_preview_member',
    methods: ['GET'],
)]
#[When('dev')]
final class PreviewMemberSetupController extends AppController
{
    public function __construct(
        private readonly ShowProjectSetupHandler $showSetup,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        $view = ($this->showSetup)(new ShowProjectSetupCommand($project, true));
        $member = new ProjectSetupView($view->project, ProjectSetupState::Member, $view->repositories, $view->initRunning, $view->initFinished, $view->bridgeConnected, $view->mergeLines);

        return $this->render('@Readiness/setup/preview_member_setup.html.twig', ['setup' => $member]);
    }
}
