<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller;

use App\Controller\AppController;
use App\Module\Insights\Command\ShowDashboardGraphCommand;
use App\Module\Insights\Command\ShowDashboardGraphHandler;
use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** One tile for the lazy frame of a graph with no kept result. It computes the graph and keeps the result. */
#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/analytics/dashboard/graphs/{graphId}',
    name: 'app_project_analytics_dashboard_graph',
    requirements: ['graphId' => Requirement::UUID],
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
class ShowDashboardGraphController extends AppController
{
    public function __construct(
        private readonly ShowDashboardGraphHandler $showGraph,
    ) {
    }

    public function __invoke(
        Project $project,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(graphId, project)')] InsightsSavedGraph $graph,
    ): Response {
        return $this->render('@Insights/_dashboard_tile_frame.html.twig', [
            'project' => $project,
            'tile' => ($this->showGraph)(new ShowDashboardGraphCommand($graph)),
        ]);
    }
}
