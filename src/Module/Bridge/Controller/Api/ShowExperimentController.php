<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicGet;
use App\Api\OpenApi\SuccessResponse;
use App\Controller\AppController;
use App\Module\Bridge\Command\ShowExperimentCommand;
use App\Module\Bridge\Command\ShowExperimentHandler;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Security\ReachableProject;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[PublicGet(
    operationId: 'getExperiment',
    summary: 'Read the comparison of an experiment',
    tags: ['Experiments'],
    description: 'The headline, the variants and the metrics that the Comparison tab shows. The cards stay on `GET .../experiments/{experiment}/cards`. `confidenceLevel` is the level of every range: 0.95 for one comparison or none, and 1 - 0.05 / N for N comparisons. A money value is in US dollars, a duration of a built-in metric in milliseconds, a duration of a metric that the workflow template declares in hours, and a ratio is between 0 and 1.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'experiment', description: 'The name of the experiment, as the runs name it.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'php-lsp-4'),
    ],
    responses: [
        new SuccessResponse(200, 'The comparison of the experiment.', ExperimentResponse::class),
        new ProblemResponse(404, ['project_not_found', 'experiment_not_found', 'route_not_found']),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/experiments/{experiment}',
    name: 'api_experiment_show',
    requirements: ['experiment' => WorkerRun::EXPERIMENT_NAME],
    methods: ['GET'],
)]
final class ShowExperimentController extends AppController
{
    public function __construct(
        private readonly ShowExperimentHandler $showExperiment,
        private readonly ExperimentReportPayload $payload,
        private readonly ExperimentApiProblems $problems,
    ) {
    }

    public function __invoke(#[ReachableProject] Project $project, string $experiment): JsonResponse
    {
        $report = ($this->showExperiment)(new ShowExperimentCommand(project: $project, experiment: $experiment, limit: 1))
            ?? throw $this->problems->experimentNotFound();

        return new JsonResponse($this->payload->forReport($report));
    }
}
