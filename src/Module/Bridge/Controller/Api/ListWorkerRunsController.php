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
use App\Module\Bridge\Command\ListWorkerRunReadingsCommand;
use App\Module\Bridge\Command\ListWorkerRunReadingsHandler;
use App\Module\Bridge\Command\PageWorkerRunsCommand;
use App\Module\Bridge\Command\PageWorkerRunsHandler;
use App\Module\Bridge\Entity\WorkerRun;
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
    operationId: 'listWorkerRuns',
    summary: 'List the worker runs',
    tags: ['Worker runs'],
    description: 'The runs of the project, the run whose first report came last first. A worker run is one run of a bridge worker on one subject, which is usually a card. A filter narrows the list, and all filters apply together. `endedAfter` and `endedBefore` are inclusive and read the end of a run, or the time of its first report for a run with no end. A date with no time reads as midnight UTC, and a time with no offset reads as UTC. Each row carries the figures of the run: its usage, its model and its metrics. `GET .../runs/{run}` reads one run in full. The state of the project can change between two pages of a walk, so a run that arrives meanwhile can be missed.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'limit', description: 'The most rows to return.', in: 'query', schema: new OA\Schema(type: 'integer', default: PageRequest::DEFAULT_LIMIT, maximum: PageRequest::MAX_LIMIT, minimum: 1)),
        new OA\Parameter(name: 'cursor', description: 'The opaque position from the `next` URL of the last page.', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'state', description: 'Only runs in one of these states, such as failed, gave-up, blocked, timed-out or lost. Repeat the parameter, or separate the states with commas.', in: 'query', schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string', enum: ['queued', 'replaced', 'resumed', 'skipped', 'preparing', 'running', 'stopping', 'stopped', 'waiting-for-person', 'dropped', 'usage-limit', 'network', 'succeeded', 'failed', 'not-started', 'no-result', 'unfinished', 'blocked', 'waiting-on-forge', 'gave-up', 'timed-out', 'lost', 'closed']))),
        new OA\Parameter(name: 'card', description: 'Only the runs of the card with this number.', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'workKind', description: 'Only the runs of work requests of this kind, such as implement or fix.', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'bridge', description: 'Only the runs of this bridge.', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'harness', description: 'Only the runs of this harness, such as claude-code or codex.', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'account', description: 'Only the runs of this harness account.', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'model', description: 'Only the runs of this model.', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'endedAfter', description: 'Only runs that ended at or after this ISO 8601 date or time.', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'endedBefore', description: 'Only runs that ended at or before this ISO 8601 date or time.', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'q', description: 'Words to find in the card number, the work kind and the output, or a whole run id.', in: 'query', schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new SuccessResponse(200, 'One page of worker runs.', WorkerRunListResponse::class, page: true),
        new ProblemResponse(400, ['invalid_cursor']),
        new ProblemResponse(404, ['project_not_found', 'route_not_found']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/runs',
    name: 'api_worker_run_list',
    methods: ['GET'],
)]
final class ListWorkerRunsController extends AppController
{
    private const string SORT = 'receivedAt';

    public function __construct(
        private readonly PageWorkerRunsHandler $pageRuns,
        private readonly ListWorkerRunReadingsHandler $listReadings,
        private readonly WorkerRunApiPayload $payload,
    ) {
    }

    public function __invoke(Request $request, #[ReachableProject] Project $project): JsonResponse
    {
        $filters = WorkerRunListFilters::fromRequest($request);
        $page = PageRequest::fromRequest($request, self::SORT, $filters->cursorFilters);
        $afterAt = null;
        $afterId = null;
        if (null !== $page->cursor) {
            [$afterAt, $afterId] = $page->cursor->createdPosition();
        }

        $view = ($this->pageRuns)(new PageWorkerRunsCommand($project, $filters->query, $page->limit, $afterAt, $afterId));
        $readings = ($this->listReadings)(new ListWorkerRunReadingsCommand($project, $view->runs));

        $next = null;
        $last = [] === $view->runs ? null : array_last($view->runs);
        if ($view->hasMore && null !== $last) {
            $next = Cursor::afterCreated(self::SORT, $last->receivedAt, $last->id ?? throw new \LogicException('A stored run has an id.'), $filters->cursorFilters);
        }

        return new Page($view->runs, $next)->toResponse($request, fn (WorkerRun $run): array => $this->payload->forRow($run, $view->pending[(string) $run->id] ?? null, $readings));
    }
}
