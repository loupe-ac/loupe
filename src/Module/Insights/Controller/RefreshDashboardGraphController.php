<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller;

use App\Controller\AppController;
use App\Module\Insights\Command\RetryGraphRefreshCommand;
use App\Module\Insights\Command\RetryGraphRefreshHandler;
use App\Module\Insights\Command\ShowDashboardGraphCommand;
use App\Module\Insights\Command\ShowDashboardGraphHandler;
use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\Turbo\TurboBundle;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

/** Retry on a slow tile. It queues the graph again and answers the waiting tile, so the notice timer starts over. */
#[CsrfToken('insights-graph-refresh')]
#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/analytics/dashboard/graphs/{graphId}/refresh',
    name: 'app_project_analytics_dashboard_graph_refresh',
    requirements: ['graphId' => Requirement::UUID],
    methods: ['POST'],
)]
class RefreshDashboardGraphController extends AppController
{
    public function __construct(
        private readonly RetryGraphRefreshHandler $retry,
        private readonly ShowDashboardGraphHandler $showGraph,
    ) {
    }

    public function __invoke(
        Request $request,
        Project $project,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(graphId, project)')] InsightsSavedGraph $graph,
    ): Response {
        ($this->retry)(new RetryGraphRefreshCommand($graph));

        if (TurboBundle::STREAM_FORMAT !== $request->getPreferredFormat()) {
            return $this->redirectToRoute('app_project_analytics_dashboard', ['id' => (string) $project->id]);
        }

        $html = $this->renderView('@Insights/_dashboard_tile.stream.html.twig', [
            'project' => $project,
            'tile' => ($this->showGraph)(new ShowDashboardGraphCommand($graph)),
        ]);

        return new Response($html, Response::HTTP_OK, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
    }
}
