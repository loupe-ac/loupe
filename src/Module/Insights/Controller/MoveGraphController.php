<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller;

use App\Controller\AppController;
use App\Module\Insights\Command\MoveSavedGraphCommand;
use App\Module\Insights\Command\MoveSavedGraphHandler;
use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Entity\SavedGraphDirection;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('insights-dashboard-graph-move')]
#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/analytics/dashboard/graphs/{graphId}/move/{direction}',
    name: 'app_project_analytics_dashboard_graph_move',
    requirements: ['graphId' => Requirement::UUID, 'direction' => 'up|down'],
    methods: ['POST'],
)]
class MoveGraphController extends AppController
{
    public function __construct(
        private readonly MoveSavedGraphHandler $moveGraph,
    ) {
    }

    public function __invoke(
        Project $project,
        SavedGraphDirection $direction,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(graphId, project)')] InsightsSavedGraph $graph,
    ): Response {
        ($this->moveGraph)(new MoveSavedGraphCommand($graph, SavedGraphDirection::Up === $direction ? -1 : 1));

        return $this->redirectToRoute('app_project_analytics_dashboard', ['id' => (string) $project->id]);
    }
}
