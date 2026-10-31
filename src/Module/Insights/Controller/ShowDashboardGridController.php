<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller;

use App\Controller\AppController;
use App\Module\Insights\Command\ShowDashboardCommand;
use App\Module\Insights\Command\ShowDashboardHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\Turbo\TurboBundle;

/** The tile grid as a Turbo stream, for a Dashboard that heard a graph change. It reads kept results and computes nothing. */
#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/analytics/dashboard/grid',
    name: 'app_project_analytics_dashboard_grid',
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
class ShowDashboardGridController extends AppController
{
    public function __construct(
        private readonly ShowDashboardHandler $showDashboard,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        $view = ($this->showDashboard)(new ShowDashboardCommand($project));

        return new Response(
            $this->renderView('@Insights/_dashboard_grid.stream.html.twig', ['project' => $view->project, 'view' => $view]),
            Response::HTTP_OK,
            ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE],
        );
    }
}
