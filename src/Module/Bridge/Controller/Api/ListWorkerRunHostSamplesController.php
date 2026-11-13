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
use App\Module\Bridge\Command\PageBridgeHostSamplesCommand;
use App\Module\Bridge\Command\PageBridgeHostSamplesHandler;
use App\Module\Bridge\Service\WorkerRunLookup;
use App\Module\Bridge\ValueObject\BridgeHostSampleReport;
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
    operationId: 'listWorkerRunHostSamples',
    summary: 'List the host samples of a worker run',
    tags: ['Worker runs'],
    description: 'The readings of the machine that ran the bridge, oldest first, from the start of the run to its end, or to now while the run is open. They describe the whole machine, so they cover every run on that bridge in that window, not only this run. A bridge sends samples only when the instance flag `bridge.host_sampling_enabled` is on and its rule file does not set `collect: false`, so a run can have none. `note` says why the list is empty when the run has no bridge or no start.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'run', description: 'The id of a worker run of the project, from the run list.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'limit', description: 'The most rows to return.', in: 'query', schema: new OA\Schema(type: 'integer', default: PageRequest::DEFAULT_LIMIT, maximum: PageRequest::MAX_LIMIT, minimum: 1)),
        new OA\Parameter(name: 'cursor', description: 'The opaque position from the `next` URL of the last page.', in: 'query', schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new SuccessResponse(200, 'One page of host samples.', WorkerRunHostSampleListResponse::class, page: true),
        new ProblemResponse(400, ['invalid_cursor']),
        new ProblemResponse(404, ['project_not_found', 'run_not_found', 'route_not_found']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/runs/{run}/host-samples',
    name: 'api_worker_run_host_sample_list',
    requirements: ['run' => WorkerRunApiRoutes::RUN],
    methods: ['GET'],
)]
final class ListWorkerRunHostSamplesController extends AppController
{
    private const string SORT = 'sampledAt';

    public function __construct(
        private readonly WorkerRunLookup $lookup,
        private readonly PageBridgeHostSamplesHandler $pageSamples,
        private readonly WorkerRunApiPayload $payload,
        private readonly WorkerRunApiProblems $problems,
    ) {
    }

    public function __invoke(Request $request, #[ReachableProject] Project $project, string $run): JsonResponse
    {
        $found = $this->lookup->find($project, $run) ?? throw $this->problems->runNotFound();
        $page = PageRequest::fromRequest($request, self::SORT);

        $view = ($this->pageSamples)(new PageBridgeHostSamplesCommand($found, $page->limit, $page->cursor?->timePosition()));

        $next = null;
        $last = [] === $view->samples ? null : array_last($view->samples);
        if ($view->hasMore && null !== $last) {
            $next = Cursor::afterTime(self::SORT, $last->sampledAt);
        }

        return new Page($view->samples, $next)->toResponse($request, fn (BridgeHostSampleReport $sample): array => $this->payload->forHostSample($sample), ['note' => $view->note]);
    }
}
