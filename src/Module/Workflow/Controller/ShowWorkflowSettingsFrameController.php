<?php

declare(strict_types=1);

namespace App\Module\Workflow\Controller;

use App\Controller\AppController;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Workflow\Command\ShowWorkflowSettingsCommand;
use App\Module\Workflow\Command\ShowWorkflowSettingsHandler;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The live frame of the workflow settings page, which the open page reads again after a column change. */
#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/workflow/frame',
    name: 'app_project_workflow_frame',
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
final class ShowWorkflowSettingsFrameController extends AppController
{
    public function __construct(
        private readonly ShowWorkflowSettingsHandler $showWorkflowSettings,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        $view = ($this->showWorkflowSettings)(new ShowWorkflowSettingsCommand($project));

        return $this->render('@Workflow/_workflow_settings_frame.html.twig', [
            'project' => $view->project,
            'template' => $view->template,
        ]);
    }
}
