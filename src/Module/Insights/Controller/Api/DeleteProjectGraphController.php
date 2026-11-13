<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicDelete;
use App\Api\OpenApi\SuccessResponse;
use App\Controller\AppController;
use App\Module\Insights\Command\RemoveSavedGraphCommand;
use App\Module\Insights\Command\RemoveSavedGraphHandler;
use App\Module\Insights\Service\InsightsApiProblems;
use App\Module\Insights\Service\SavedGraphLookup;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Security\ReachableProject;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[PublicDelete(
    operationId: 'deleteGraph',
    summary: 'Remove a saved graph from the Dashboard',
    tags: ['Graphs'],
    description: 'The removal takes effect at once, with no confirmation step. A removed graph can come back for one day with a restore.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'graph', description: 'The id of a saved graph, from the graph list.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new SuccessResponse(200, 'The graph is removed.', GraphRemovedResponse::class),
        new ProblemResponse(404, ['project_not_found', 'graph_not_found', 'route_not_found']),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/graphs/{graph}',
    name: 'api_graph_delete',
    requirements: ['graph' => Requirement::UUID],
    methods: ['DELETE'],
)]
final class DeleteProjectGraphController extends AppController
{
    public function __construct(
        private readonly SavedGraphLookup $lookup,
        private readonly RemoveSavedGraphHandler $removeGraph,
        private readonly InsightsApiProblems $problems,
    ) {
    }

    public function __invoke(#[ReachableProject] Project $project, string $graph): JsonResponse
    {
        $found = $this->lookup->find($project, $graph);
        if (null === $found || null !== $found->removedAt) {
            throw $this->problems->graphNotFound();
        }

        try {
            ($this->removeGraph)(new RemoveSavedGraphCommand($found));
        } catch (NotFoundHttpException) {
            throw $this->problems->graphNotFound();
        }

        return new JsonResponse(new GraphRemovedResponse(($found->undoUntil() ?? throw new \LogicException('A removed graph has a removal time.'))->format(\DATE_ATOM)));
    }
}
