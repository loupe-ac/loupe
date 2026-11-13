<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicGet;
use App\Api\OpenApi\SuccessResponse;
use App\Api\Pagination\Cursor;
use App\Api\Pagination\Page;
use App\Api\Pagination\PageRequest;
use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\PageOwnerBridgesCommand;
use App\Module\Bridge\Command\PageOwnerBridgesHandler;
use App\Module\Bridge\Entity\Bridge;
use App\Security\AuthenticatedCredential;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Lists every bridge of the owner once. A credential bound to one project sees
 * the bridges that serve it, and learns no other project id.
 */
#[PublicGet(
    operationId: 'listBridges',
    summary: 'List the bridges of the account',
    tags: ['Bridges'],
    description: 'Every CLI bridge of the owner once, ordered by id, with the ids of the projects it serves. A credential bound to one project sees the bridges that serve that project, and `projectIds` then holds that project alone. `GET /api/v1/projects/{project}/bridges` lists the bridges of one project with their runs.',
    parameters: [
        new OA\Parameter(name: 'limit', description: 'The most rows to return.', in: 'query', schema: new OA\Schema(type: 'integer', default: PageRequest::DEFAULT_LIMIT, maximum: PageRequest::MAX_LIMIT, minimum: 1)),
        new OA\Parameter(name: 'cursor', description: 'The opaque position from the `next` URL of the last page.', in: 'query', schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new SuccessResponse(200, 'One page of bridges.', OwnerBridgeListResponse::class, page: true),
        new ProblemResponse(400, ['invalid_cursor']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/bridges',
    name: 'api_bridge_list',
    methods: ['GET'],
)]
final class ListBridgesController extends AppController
{
    private const string SORT = 'id';

    public function __construct(
        private readonly PageOwnerBridgesHandler $pageBridges,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly BridgeApiPayload $payload,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('The bridge list reached without an authenticated User.');
        }

        $page = PageRequest::fromRequest($request, self::SORT);
        $only = AuthenticatedCredential::of($this->tokenStorage->getToken())?->projectId;

        $view = ($this->pageBridges)(new PageOwnerBridgesCommand($user, $page->limit, $only, $page->cursor?->idPosition()));

        $next = null;
        $last = [] === $view->bridges ? null : array_last($view->bridges);
        if ($view->hasMore && null !== $last) {
            $next = Cursor::afterId(self::SORT, $last->id);
        }

        return new Page($view->bridges, $next)->toResponse($request, fn (Bridge $bridge): array => $this->payload->forOwnerBridge($bridge, $view->statuses[$bridge->id->toRfc4122()], $only?->toRfc4122()));
    }
}
