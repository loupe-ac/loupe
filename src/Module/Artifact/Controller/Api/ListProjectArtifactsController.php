<?php

declare(strict_types=1);

namespace App\Module\Artifact\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicGet;
use App\Api\OpenApi\SuccessResponse;
use App\Api\Pagination\CreatedSort;
use App\Api\Pagination\PageRequest;
use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use App\Module\Artifact\Command\PageArtifactsCommand;
use App\Module\Artifact\Command\PageArtifactsHandler;
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
    operationId: 'listArtifacts',
    summary: 'List the stored artifacts',
    tags: ['Artifacts'],
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'limit', description: 'The most rows to return.', in: 'query', schema: new OA\Schema(type: 'integer', default: PageRequest::DEFAULT_LIMIT, maximum: PageRequest::MAX_LIMIT, minimum: 1)),
        new OA\Parameter(name: 'cursor', description: 'The opaque position from the `next` URL of the last page.', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'sort', description: 'The sort order. The default is creation order, oldest first. A leading `-` sorts newest first.', in: 'query', schema: new OA\Schema(type: 'string', default: 'createdAt', enum: ['createdAt', '-createdAt'])),
        new OA\Parameter(name: 'includeArchived', description: 'Include archived rows, which are left out by default.', in: 'query', schema: new OA\Schema(type: 'boolean', default: false)),
    ],
    responses: [
        new SuccessResponse(200, 'One page of artifacts.', ArtifactListResponse::class, page: true),
        new ProblemResponse(400, ['invalid_cursor']),
        new ProblemResponse(404, ['project_not_found', 'route_not_found']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/artifacts',
    name: 'api_artifact_list',
    methods: ['GET'],
)]
final class ListProjectArtifactsController extends AppController
{
    public function __construct(
        private readonly PageArtifactsHandler $pageArtifacts,
    ) {
    }

    public function __invoke(Request $request, #[ReachableProject] Project $project): JsonResponse
    {
        $includeArchived = $request->query->all()['includeArchived'] ?? 'false';
        $parsed = \is_string($includeArchived) ? filter_var($includeArchived, \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE) : null;
        if (null === $parsed) {
            throw ApiProblemException::validationFailed([['field' => 'includeArchived', 'code' => 'include_archived_invalid', 'message' => 'The includeArchived value must be true or false.']]);
        }

        $sort = CreatedSort::fromRequest($request);
        $page = ($this->pageArtifacts)(new PageArtifactsCommand(
            project: $project,
            includeArchived: $parsed,
            page: PageRequest::fromRequest($request, $sort->value, ['includeArchived' => $parsed]),
            sort: $sort,
        ));

        return $page->toResponse($request, ArtifactSummaryResponse::fromPaged(...));
    }
}
