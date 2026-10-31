<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Insights\Command\RestoreSavedGraphCommand;
use App\Module\Insights\Command\RestoreSavedGraphHandler;
use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('insights-dashboard-graph-undo')]
#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/analytics/dashboard/graphs/{graphId}/undo',
    name: 'app_project_analytics_dashboard_graph_undo',
    requirements: ['graphId' => Requirement::UUID],
    methods: ['POST'],
)]
class UndoRemoveGraphController extends AppController
{
    public function __construct(
        private readonly RestoreSavedGraphHandler $restoreGraph,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        Project $project,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(graphId, project)')] InsightsSavedGraph $graph,
    ): Response {
        try {
            ($this->restoreGraph)(new RestoreSavedGraphCommand($graph));
        } catch (DomainErrors $e) {
            foreach ($e->errors as $field => $translationKey) {
                $this->addFlash('error', $this->translator->trans($translationKey, $e->parameters[$field] ?? []));
            }

            return $this->redirectToRoute('app_project_analytics_dashboard', ['id' => (string) $project->id, 'removed' => (string) $graph->id]);
        }

        return $this->redirectToRoute('app_project_analytics_dashboard', ['id' => (string) $project->id]);
    }
}
