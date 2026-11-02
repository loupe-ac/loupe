<?php

declare(strict_types=1);

namespace App\Module\Project\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicGet;
use App\Api\OpenApi\SuccessResponse;
use App\Api\Pagination\CreatedSort;
use App\Api\Pagination\PageRequest;
use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Project\Command\PageProjectsCommand;
use App\Module\Project\Command\PageProjectsHandler;
use App\Module\Project\Entity\Project;
use App\Security\AuthenticatedCredential;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Lists the projects the credential reaches. A credential bound to one project
 * sees that project alone, and any other credential sees every project of its owner.
 */
#[PublicGet(
    operationId: 'listProjects',
    summary: 'List the projects this credential reaches',
    tags: ['Projects'],
    parameters: [
        new OA\Parameter(name: 'limit', description: 'The most rows to return.', in: 'query', schema: new OA\Schema(type: 'integer', default: PageRequest::DEFAULT_LIMIT, maximum: PageRequest::MAX_LIMIT, minimum: 1)),
        new OA\Parameter(name: 'cursor', description: 'The opaque position from the `next` URL of the last page.', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'sort', description: 'The sort order. The default is creation order, oldest first. A leading `-` sorts newest first.', in: 'query', schema: new OA\Schema(type: 'string', default: 'createdAt', enum: ['createdAt', '-createdAt'])),
    ],
    responses: [
        new SuccessResponse(200, 'One page of projects.', ProjectListResponse::class, page: true),
        new ProblemResponse(400, ['invalid_cursor']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects',
    name: 'api_project_list',
    methods: ['GET'],
)]
final class ListProjectsController extends AppController
{
    public function __construct(
        private readonly PageProjectsHandler $pageProjects,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('The project list reached without an authenticated User.');
        }

        $sort = CreatedSort::fromRequest($request);
        $page = ($this->pageProjects)(new PageProjectsCommand(
            owner: $user,
            only: AuthenticatedCredential::of($this->tokenStorage->getToken())?->projectId,
            page: PageRequest::fromRequest($request, $sort->value),
            sort: $sort,
        ));

        return $page->toResponse($request, ProjectResponse::fromProject(...));
    }
}
