<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicGet;
use App\Api\OpenApi\SuccessResponse;
use App\Api\Problem\ApiProblem;
use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Insights\Command\ShowAnalysisCommand;
use App\Module\Insights\Command\ShowAnalysisHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Security\ReachableProject;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[PublicGet(
    operationId: 'getAnalysis',
    summary: 'Read one analysis',
    tags: ['Analytics'],
    description: 'The analysis with its cost and its proposals. An analysis of another project answers like a missing one.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'analysis', description: 'The id of the analysis.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new SuccessResponse(200, 'The analysis.', AnalysisResponse::class),
        new ProblemResponse(404, ['project_not_found', 'analysis_not_found']),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/analyses/{analysis}',
    name: 'api_analysis_show',
    requirements: ['analysis' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}'],
    methods: ['GET'],
)]
final class ShowAnalysisController extends AppController
{
    public function __construct(
        private readonly ShowAnalysisHandler $showAnalysis,
    ) {
    }

    public function __invoke(#[ReachableProject] Project $project, string $analysis): JsonResponse
    {
        try {
            $view = ($this->showAnalysis)(new ShowAnalysisCommand($project, $analysis));
        } catch (DomainErrors) {
            throw new ApiProblemException(new ApiProblem(404, 'analysis_not_found', 'Analysis not found', 'This project has no analysis with this id.'));
        }

        return new JsonResponse(AnalysisResponse::fromView($view));
    }
}
