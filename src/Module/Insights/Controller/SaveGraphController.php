<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Insights\Command\SaveSavedGraphCommand;
use App\Module\Insights\Command\SaveSavedGraphHandler;
use App\Module\Insights\Form\SavedGraphFormType;
use App\Module\Insights\Form\SavedGraphRequest;
use App\Module\Insights\Service\MetricsSaveStreamAnswers;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/analytics/metrics/graphs',
    name: 'app_project_analytics_save_graph',
    methods: ['POST'],
)]
class SaveGraphController extends AppController
{
    public function __construct(
        private readonly MetricsSaveStreamAnswers $answers,
        private readonly SaveSavedGraphHandler $saveGraph,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Project $project, Request $request): Response
    {
        $data = new SavedGraphRequest();
        $form = $this->createForm(SavedGraphFormType::class, $data);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->answers->refused($this->translator->trans('analytics.metrics.saved_graph.flash.invalid'));
        }

        try {
            $result = ($this->saveGraph)(new SaveSavedGraphCommand($project, $data->name, $data->settings()));
        } catch (DomainErrors $e) {
            return $this->answers->refused(implode(' ', array_map(
                fn (string $field, string $translationKey): string => $this->translator->trans($translationKey, $e->parameters[$field] ?? []),
                array_keys($e->errors),
                $e->errors,
            )));
        }

        return $this->answers->saved($project, $result->graph, $data, true, $this->translator->trans('analytics.metrics.saved_graph.flash.saved'));
    }
}
