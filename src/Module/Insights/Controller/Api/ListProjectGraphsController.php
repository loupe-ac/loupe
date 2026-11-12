<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicGet;
use App\Api\OpenApi\SuccessResponse;
use App\Api\Pagination\PageRequest;
use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use App\Module\Insights\Command\PageSavedGraphsCommand;
use App\Module\Insights\Command\PageSavedGraphsHandler;
use App\Module\Insights\Command\SavedGraphRow;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Security\ReachableProject;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[PublicGet(
    operationId: 'listGraphs',
    summary: 'List the saved graphs of the Dashboard',
    tags: ['Graphs'],
    description: 'The graphs come in the order the Dashboard draws them. A saved graph is a tile on the Dashboard of the owner. This route draws no chart: read the data of a graph with the metric query.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'limit', description: 'The most rows to return.', in: 'query', schema: new OA\Schema(type: 'integer', default: PageRequest::DEFAULT_LIMIT, maximum: PageRequest::MAX_LIMIT, minimum: 1)),
        new OA\Parameter(name: 'cursor', description: 'The opaque position from the `next` URL of the last page.', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'removed', description: 'True lists the graphs that were removed less than a day ago and that a restore can bring back, in place of the graphs on the Dashboard.', in: 'query', schema: new OA\Schema(type: 'boolean', default: false)),
    ],
    responses: [
        new SuccessResponse(200, 'One page of saved graphs.', GraphListResponse::class, page: true),
        new ProblemResponse(400, ['invalid_cursor']),
        new ProblemResponse(404, ['project_not_found', 'route_not_found']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/graphs',
    name: 'api_graph_list',
    methods: ['GET'],
)]
final class ListProjectGraphsController extends AppController
{
    public function __construct(
        private readonly PageSavedGraphsHandler $pageGraphs,
    ) {
    }

    public function __invoke(Request $request, #[ReachableProject] Project $project): JsonResponse
    {
        $removed = $request->query->all()['removed'] ?? 'false';
        $parsed = \is_string($removed) ? filter_var($removed, \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE) : null;
        if (null === $parsed) {
            throw ApiProblemException::validationFailed([['field' => 'removed', 'code' => 'removed_invalid', 'message' => 'The removed value must be true or false.']]);
        }

        $page = ($this->pageGraphs)(new PageSavedGraphsCommand($project, PageRequest::fromRequest($request, 'position', ['removed' => $parsed]), $parsed));

        return $page->toResponse($request, static fn (SavedGraphRow $row): GraphResponse => GraphResponse::fromRow($row));
    }
}
