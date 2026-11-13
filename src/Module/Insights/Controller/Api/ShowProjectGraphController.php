<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicGet;
use App\Api\OpenApi\SuccessResponse;
use App\Controller\AppController;
use App\Module\Insights\Command\DescribeSavedGraphCommand;
use App\Module\Insights\Command\DescribeSavedGraphHandler;
use App\Module\Insights\Service\InsightsApiProblems;
use App\Module\Insights\Service\SavedGraphLookup;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Security\ReachableProject;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[PublicGet(
    operationId: 'getGraph',
    summary: 'Read one saved graph',
    tags: ['Graphs'],
    description: 'A removed graph answers 404, also when a restore can still bring it back.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'graph', description: 'The id of a saved graph, from the graph list.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new SuccessResponse(200, 'The saved graph.', GraphResponse::class),
        new ProblemResponse(404, ['project_not_found', 'graph_not_found', 'route_not_found']),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/graphs/{graph}',
    name: 'api_graph_show',
    requirements: ['graph' => Requirement::UUID],
    methods: ['GET'],
)]
final class ShowProjectGraphController extends AppController
{
    public function __construct(
        private readonly SavedGraphLookup $lookup,
        private readonly DescribeSavedGraphHandler $describeGraph,
        private readonly InsightsApiProblems $problems,
    ) {
    }

    public function __invoke(#[ReachableProject] Project $project, string $graph): JsonResponse
    {
        $found = $this->lookup->find($project, $graph);
        if (null === $found || null !== $found->removedAt) {
            throw $this->problems->graphNotFound();
        }

        return new JsonResponse(GraphResponse::fromRow(($this->describeGraph)(new DescribeSavedGraphCommand($found))));
    }
}
