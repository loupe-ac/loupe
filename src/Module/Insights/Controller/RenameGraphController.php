<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller;

use App\Controller\AppController;
use App\Module\Insights\Command\UpdateSavedGraphFieldsCommand;
use App\Module\Insights\Command\UpdateSavedGraphFieldsHandler;
use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Form\RenameGraphRequest;
use App\Module\Insights\Service\DashboardGraphForms;
use App\Module\Insights\Service\DashboardStreamAnswers;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/analytics/dashboard/graphs/{graphId}/rename',
    name: 'app_project_analytics_dashboard_graph_rename',
    requirements: ['graphId' => Requirement::UUID],
    methods: ['POST'],
)]
class RenameGraphController extends AppController
{
    public function __construct(
        private readonly DashboardStreamAnswers $answers,
        private readonly UpdateSavedGraphFieldsHandler $handler,
        private readonly DashboardGraphForms $forms,
    ) {
    }

    public function __invoke(
        Project $project,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(graphId, project)')] InsightsSavedGraph $graph,
        Request $request,
    ): Response {
        $data = new RenameGraphRequest();
        $form = $this->forms->rename($graph, $data);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->answers->invalid();
        }

        ($this->handler)(new UpdateSavedGraphFieldsCommand($project, (string) $graph->id, name: $data->name ?? throw new \LogicException('A valid name is not null.')));

        return $this->answers->tile($project, $graph);
    }
}
