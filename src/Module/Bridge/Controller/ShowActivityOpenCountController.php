<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller;

use App\Controller\AppController;
use App\Module\Bridge\Command\ShowActivityOpenCountCommand;
use App\Module\Bridge\Command\ShowActivityOpenCountHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The sidebar pill of the Activity link alone, for the frame the live-pill controller reloads. */
#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/activity/open-count',
    name: 'app_project_activity_open_count',
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
final class ShowActivityOpenCountController extends AppController
{
    public function __construct(
        private readonly ShowActivityOpenCountHandler $showOpenCount,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        $response = $this->render('@Bridge/_activity_open_count.html.twig', [
            'project' => $project,
            'openCount' => ($this->showOpenCount)(new ShowActivityOpenCountCommand($project)),
        ]);
        // A reload follows a change, so a cached count would show the old one.
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
