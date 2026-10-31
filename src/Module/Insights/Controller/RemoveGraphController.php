<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller;

use App\Controller\AppController;
use App\Module\Insights\Command\RemoveSavedGraphCommand;
use App\Module\Insights\Command\RemoveSavedGraphHandler;
use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
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
        private readonly RemoveSavedGraphHandler $removeGraph,
    ) {
    }

    public function __invoke(
        Project $project,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(graphId, project)')] InsightsSavedGraph $graph,
    ): Response {
        ($this->removeGraph)(new RemoveSavedGraphCommand($graph));

        return $this->redirectToRoute('app_project_analytics_dashboard', ['id' => (string) $project->id, 'removed' => (string) $graph->id]);
    }
}
