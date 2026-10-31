<?php

declare(strict_types=1);

namespace App\Module\OpenRouter\Controller;

use App\Controller\AppController;
use App\Module\OpenRouter\Command\ShowProjectOpenRouterCommand;
use App\Module\OpenRouter\Command\ShowProjectOpenRouterHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The turbo frame of the project key status. The page renders the same partial. */
#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/settings/openrouter/status',
    name: 'app_project_openrouter_status',
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
final class ShowProjectOpenRouterStatusController extends AppController
{
    public function __construct(
        private readonly ShowProjectOpenRouterHandler $showProject,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        return $this->render('@OpenRouter/_project_key_status.html.twig', [
            'project' => $project,
            'view' => ($this->showProject)(new ShowProjectOpenRouterCommand($project)),
        ]);
    }
}
