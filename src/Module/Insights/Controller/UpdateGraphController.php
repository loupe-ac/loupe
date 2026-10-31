<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Insights\Command\UpdateSavedGraphCommand;
use App\Module\Insights\Command\UpdateSavedGraphHandler;
use App\Module\Insights\Form\SavedGraphFormType;
use App\Module\Insights\Form\SavedGraphRequest;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/analytics/metrics/graphs/{graphId}',
    name: 'app_project_analytics_update_graph',
    methods: ['POST'],
)]
class UpdateGraphController extends AppController
{
    public function __construct(
        private readonly UpdateSavedGraphHandler $updateGraph,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Project $project, string $graphId, Request $request): Response
    {
        $data = new SavedGraphRequest();
        $form = $this->createForm(SavedGraphFormType::class, $data);
        $form->handleRequest($request);
        $params = ['id' => (string) $project->id, 'graph' => $graphId] + $data->settings();

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', $this->translator->trans('analytics.metrics.saved_graph.flash.invalid'));

            return $this->redirectToRoute('app_project_analytics_metrics', $params);
        }

        try {
            $result = ($this->updateGraph)(new UpdateSavedGraphCommand($project, $graphId, $data->name, $data->settings()));
        } catch (DomainErrors $e) {
            foreach ($e->errors as $field => $translationKey) {
                $this->addFlash('error', $this->translator->trans($translationKey, $e->parameters[$field] ?? []));
            }

            return $this->redirectToRoute('app_project_analytics_metrics', $params);
        }

        $this->addFlash('success', $this->translator->trans('analytics.metrics.saved_graph.flash.updated'));

        return $this->redirectToRoute('app_project_analytics_metrics', ['id' => (string) $project->id, 'graph' => (string) $result->graph->id] + $result->query->routeParams());
    }
}
