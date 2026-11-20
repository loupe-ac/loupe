<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Insights\Command\UpdateSavedGraphFieldsCommand;
use App\Module\Insights\Command\UpdateSavedGraphFieldsHandler;
use App\Module\Insights\Form\SavedGraphFormType;
use App\Module\Insights\Form\SavedGraphRequest;
use App\Module\Insights\Service\SavedGraphCap;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/analytics/metrics/graphs/{graphId}',
    name: 'app_project_analytics_update_graph',
    methods: ['POST'],
)]
class UpdateGraphController extends AppController
{
    public function __construct(
        private readonly SavedGraphCap $cap,
        private readonly UpdateSavedGraphFieldsHandler $updateGraph,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Project $project, string $graphId, Request $request): Response
    {
        $data = new SavedGraphRequest();
        $form = $this->createForm(SavedGraphFormType::class, $data);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $html = $this->renderView('_flash.stream.html.twig', ['severity' => 'error', 'message' => $this->translator->trans('analytics.metrics.saved_graph.flash.invalid')]);

            return new Response($html, Response::HTTP_UNPROCESSABLE_ENTITY, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
        }

        try {
            $result = ($this->updateGraph)(new UpdateSavedGraphFieldsCommand($project, $graphId, name: $data->name, settings: $data->settings()));
        } catch (DomainErrors $e) {
            $html = $this->renderView('_flash.stream.html.twig', ['severity' => 'error', 'message' => implode(' ', array_map(
                fn (string $field, string $translationKey): string => $this->translator->trans($translationKey, $e->parameters[$field] ?? []),
                array_keys($e->errors),
                $e->errors,
            ))]);

            return new Response($html, Response::HTTP_UNPROCESSABLE_ENTITY, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
        }

        $saveForm = $this->createForm(SavedGraphFormType::class, $data, ['action' => $this->generateUrl('app_project_analytics_save_graph', ['id' => (string) $project->id])]);
        $html = $this->renderView('@Insights/_metrics_save.stream.html.twig', [
            'project' => $project,
            'graph' => $result->graph,
            'saveForm' => $saveForm->createView(),
            'capReached' => $this->cap->isReached($project),
            'capLimit' => $this->cap->limit(),
            'saved' => false,
            'severity' => 'success',
            'message' => $this->translator->trans('analytics.metrics.saved_graph.flash.updated'),
        ]);

        return new Response($html, Response::HTTP_OK, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
    }
}
