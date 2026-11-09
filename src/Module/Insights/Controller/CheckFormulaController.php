<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller;

use App\Controller\AppController;
use App\Module\Insights\Command\CheckFormulaCommand;
use App\Module\Insights\Command\CheckFormulaHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Tells the Metrics tab whether a formula can run, as the owner types. It reads no run data. */
#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/analytics/metrics/formula-check',
    name: 'app_project_analytics_formula_check',
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
class CheckFormulaController extends AppController
{
    public function __construct(
        private readonly CheckFormulaHandler $checkFormula,
    ) {
    }

    public function __invoke(Project $project, Request $request): JsonResponse
    {
        $result = ($this->checkFormula)(new CheckFormulaCommand(
            $project,
            $request->query->getString('rows'),
            $request->query->getString('formula'),
            $request->query->getString('format', 'auto'),
        ));

        return new JsonResponse(['ok' => $result->ok, 'error' => $result->error]);
    }
}
