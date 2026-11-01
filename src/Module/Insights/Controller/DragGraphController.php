<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller;

use App\Controller\AppController;
use App\Module\Insights\Command\MoveSavedGraphCommand;
use App\Module\Insights\Command\MoveSavedGraphHandler;
use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Form\DragGraphRequest;
use App\Module\Insights\Service\DashboardGraphForms;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/analytics/dashboard/graphs/{graphId}/drag',
    name: 'app_project_analytics_dashboard_graph_drag',
    requirements: ['graphId' => Requirement::UUID],
    methods: ['POST'],
)]
class DragGraphController extends AppController
{
    public function __construct(
        private readonly MoveSavedGraphHandler $handler,
        private readonly DashboardGraphForms $forms,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        Project $project,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(graphId, project)')] InsightsSavedGraph $graph,
        Request $request,
    ): Response {
        $data = new DragGraphRequest();
        $form = $this->forms->drag($graph, $data);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', $this->translator->trans('analytics.dashboard.flash.invalid'));
        } else {
            ($this->handler)(new MoveSavedGraphCommand($graph, $data->steps ?? throw new \LogicException('A valid step count is not null.')));
        }

        return $this->redirectToRoute('app_project_analytics_dashboard', ['id' => (string) $project->id]);
    }
}
