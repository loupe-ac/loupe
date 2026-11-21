<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller;

use App\Controller\AppController;
use App\Module\Insights\Command\RemoveSavedGraphCommand;
use App\Module\Insights\Command\RemoveSavedGraphHandler;
use App\Module\Insights\Command\ShowDashboardCommand;
use App\Module\Insights\Command\ShowDashboardHandler;
use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\Turbo\TurboBundle;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('insights-dashboard-graph-remove')]
#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/analytics/dashboard/graphs/{graphId}/remove',
    name: 'app_project_analytics_dashboard_graph_remove',
    requirements: ['graphId' => Requirement::UUID],
    methods: ['POST'],
)]
class RemoveGraphController extends AppController
{
    public function __construct(
        private readonly ShowDashboardHandler $showDashboard,
        private readonly RemoveSavedGraphHandler $removeGraph,
    ) {
    }

    public function __invoke(
        Project $project,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(graphId, project)')] InsightsSavedGraph $graph,
    ): Response {
        ($this->removeGraph)(new RemoveSavedGraphCommand($graph));

        $view = ($this->showDashboard)(new ShowDashboardCommand($project));
        $html = $this->renderView('@Insights/_dashboard_change.stream.html.twig', ['project' => $view->project, 'view' => $view, 'removed' => $graph]);

        return new Response($html, Response::HTTP_OK, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
    }
}
