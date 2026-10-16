<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller;

use App\Controller\AppController;
use App\Module\Bridge\Command\ShowAgentsCountCommand;
use App\Module\Bridge\Command\ShowAgentsCountHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The sidebar pill of the Agents link alone, for the frame the live-pill controller reloads. */
#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/agents/count',
    name: 'app_project_agents_count',
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
final class ShowAgentsCountController extends AppController
{
    public function __construct(
        private readonly ShowAgentsCountHandler $showCount,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        $response = $this->render('@Bridge/_agents_count.html.twig', [
            'project' => $project,
            'figures' => ($this->showCount)(new ShowAgentsCountCommand($project)),
        ]);
        // A reload follows a change, so a cached count would show the old one.
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
