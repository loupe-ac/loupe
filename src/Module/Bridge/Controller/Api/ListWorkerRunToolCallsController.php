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
use App\Module\Bridge\Command\PageWorkerRunToolCallsCommand;
use App\Module\Bridge\Command\PageWorkerRunToolCallsHandler;
use App\Module\Bridge\Entity\WorkerRunToolCall;
use App\Module\Bridge\Service\WorkerRunLookup;
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
    operationId: 'listWorkerRunToolCalls',
    summary: 'List the tool calls of a worker run',
    tags: ['Worker runs'],
    description: 'The calls in the order the worker made them. The bridge reads the calls from the stream of the worker and sends them when the run ends, so an open run, or a run of an older bridge, can have none. `fullText` is null unless the project collects the full text of the calls.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'run', description: 'The id of a worker run of the project, from the run list.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'limit', description: 'The most rows to return.', in: 'query', schema: new OA\Schema(type: 'integer', default: PageRequest::DEFAULT_LIMIT, maximum: PageRequest::MAX_LIMIT, minimum: 1)),
        new OA\Parameter(name: 'cursor', description: 'The opaque position from the `next` URL of the last page.', in: 'query', schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new SuccessResponse(200, 'One page of tool calls.', WorkerRunToolCallListResponse::class, page: true),
        new ProblemResponse(400, ['invalid_cursor']),
        new ProblemResponse(404, ['project_not_found', 'run_not_found', 'route_not_found']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/runs/{run}/tool-calls',
    name: 'api_worker_run_tool_call_list',
    requirements: ['run' => WorkerRunApiRoutes::RUN],
    methods: ['GET'],
)]
final class ListWorkerRunToolCallsController extends AppController
{
    private const string SORT = 'seq';

    public function __construct(
        private readonly WorkerRunLookup $lookup,
        private readonly PageWorkerRunToolCallsHandler $pageCalls,
        private readonly WorkerRunApiPayload $payload,
        private readonly WorkerRunApiProblems $problems,
    ) {
    }

    public function __invoke(Request $request, #[ReachableProject] Project $project, string $run): JsonResponse
    {
        $found = $this->lookup->find($project, $run) ?? throw $this->problems->runNotFound();
        $page = PageRequest::fromRequest($request, self::SORT);

        $view = ($this->pageCalls)(new PageWorkerRunToolCallsCommand($found, $page->limit, $page->cursor?->numberPosition() ?? 0));

        $next = null;
        $last = [] === $view->calls ? null : array_last($view->calls);
        if ($view->hasMore && null !== $last) {
            $next = Cursor::afterNumber(self::SORT, $last->seq);
        }

        return new Page($view->calls, $next)->toResponse($request, fn (WorkerRunToolCall $call): array => $this->payload->forToolCall($call));
    }
}
