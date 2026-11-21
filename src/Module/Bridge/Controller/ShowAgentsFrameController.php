<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller;

use App\Controller\AppController;
use App\Module\Bridge\Command\ListAgentsCommand;
use App\Module\Bridge\Command\ListAgentsHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The live frame of the Agents page, which the open page reads again after a worker run signal. */
#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/agents/frame',
    name: 'app_project_agents_frame',
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
final class ShowAgentsFrameController extends AppController
{
    public function __construct(
        private readonly ListAgentsHandler $listAgents,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        $view = ($this->listAgents)(new ListAgentsCommand($project));

        return $this->render('@Bridge/_agents_frame.html.twig', [
            'project' => $view->project,
            'connections' => $view->connections,
        ]);
    }
}
