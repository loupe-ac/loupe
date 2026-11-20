<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller;

use App\Controller\AppController;
use App\Module\Insights\Command\ShowDashboardGraphCommand;
use App\Module\Insights\Command\ShowDashboardGraphHandler;
use App\Module\Insights\Command\UpdateSavedGraphFieldsCommand;
use App\Module\Insights\Command\UpdateSavedGraphFieldsHandler;
use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Form\GraphWidthRequest;
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
use Symfony\UX\Turbo\TurboBundle;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/analytics/dashboard/graphs/{graphId}/width',
    name: 'app_project_analytics_dashboard_graph_width',
    requirements: ['graphId' => Requirement::UUID],
    methods: ['POST'],
)]
class ResizeGraphController extends AppController
{
    public function __construct(
        private readonly ShowDashboardGraphHandler $showGraph,
        private readonly UpdateSavedGraphFieldsHandler $handler,
        private readonly DashboardGraphForms $forms,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        Project $project,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(graphId, project)')] InsightsSavedGraph $graph,
        Request $request,
    ): Response {
        $data = new GraphWidthRequest();
        $form = $this->forms->width($graph, $data);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $html = $this->renderView('_flash.stream.html.twig', ['severity' => 'error', 'message' => $this->translator->trans('analytics.dashboard.flash.invalid')]);

            return new Response($html, Response::HTTP_UNPROCESSABLE_ENTITY, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
        }

        ($this->handler)(new UpdateSavedGraphFieldsCommand($project, (string) $graph->id, width: $data->width ?? throw new \LogicException('A valid width is not null.')));

        $html = $this->renderView('@Insights/_dashboard_tile.stream.html.twig', [
            'project' => $project,
            'tile' => ($this->showGraph)(new ShowDashboardGraphCommand($graph)),
        ]);

        return new Response($html, Response::HTTP_OK, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
    }
}
