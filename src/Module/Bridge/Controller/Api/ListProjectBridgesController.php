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
use App\Module\Bridge\Command\ListAgentsCommand;
use App\Module\Bridge\Command\ListAgentsHandler;
use App\Module\Bridge\Command\ListOpenWorkerRunsCommand;
use App\Module\Bridge\Command\ListOpenWorkerRunsHandler;
use App\Module\Bridge\View\AgentConnection;
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
    operationId: 'listProjectBridges',
    summary: 'List the bridges that serve a project',
    tags: ['Bridges'],
    description: 'The CLI bridges that follow the project, ordered by id. A bridge runs the workers of the project on a machine. Each row carries the open runs of this project on the bridge and the work requests of this project that the server refused to it. `GET /api/v1/bridges` lists every bridge of the owner once.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'limit', description: 'The most rows to return.', in: 'query', schema: new OA\Schema(type: 'integer', default: PageRequest::DEFAULT_LIMIT, maximum: PageRequest::MAX_LIMIT, minimum: 1)),
        new OA\Parameter(name: 'cursor', description: 'The opaque position from the `next` URL of the last page.', in: 'query', schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new SuccessResponse(200, 'One page of bridges.', ProjectBridgeListResponse::class, page: true),
        new ProblemResponse(400, ['invalid_cursor']),
        new ProblemResponse(404, ['project_not_found', 'route_not_found']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/bridges',
    name: 'api_project_bridge_list',
    methods: ['GET'],
)]
final class ListProjectBridgesController extends AppController
{
    private const string SORT = 'id';

    public function __construct(
        private readonly ListAgentsHandler $listAgents,
        private readonly ListOpenWorkerRunsHandler $listOpenRuns,
        private readonly BridgeApiPayload $payload,
    ) {
    }

    public function __invoke(Request $request, #[ReachableProject] Project $project): JsonResponse
    {
        $page = PageRequest::fromRequest($request, self::SORT);

        $view = ($this->listAgents)(new ListAgentsCommand($project, $page->cursor?->idPosition(), $page->limit));

        $openRuns = [];
        foreach (($this->listOpenRuns)(new ListOpenWorkerRunsCommand($project))->runs as $run) {
            if (null !== $run->bridgeId) {
                $openRuns[$run->bridgeId->toRfc4122()][] = $run;
            }
        }

        $next = null;
        $last = [] === $view->connections ? null : array_last($view->connections);
        if ($view->hasMore && null !== $last) {
            $next = Cursor::afterId(self::SORT, $last->bridge->id);
        }

        return new Page($view->connections, $next)->toResponse($request, fn (AgentConnection $connection): array => $this->payload->forProjectBridge($connection, (string) $project->id, $openRuns[$connection->bridge->id->toRfc4122()] ?? []));
    }
}
