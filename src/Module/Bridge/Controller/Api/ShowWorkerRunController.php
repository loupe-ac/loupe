<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicGet;
use App\Api\OpenApi\SuccessResponse;
use App\Controller\AppController;
use App\Module\Bridge\Command\ListWorkerRunReadingsCommand;
use App\Module\Bridge\Command\ListWorkerRunReadingsHandler;
use App\Module\Bridge\Command\ShowWorkerRunSeriesCommand;
use App\Module\Bridge\Command\ShowWorkerRunSeriesHandler;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Service\WorkerRunLookup;
use App\Module\Bridge\ValueObject\BridgeCommandState;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Security\ReachableProject;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[PublicGet(
    operationId: 'getWorkerRun',
    summary: 'Read one worker run in full',
    tags: ['Worker runs'],
    description: 'The run with its output and its state changes, every run of its series, and every command that a person, an agent or an API key sent to a bridge for a run of the series. A series starts with one run, and each resume of a run starts a new run that continues it. When an older run was deleted, its continuations lose their link to it, and the series lists only the branch of the oldest run that remains. The tool calls and the host samples have their own routes.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'run', description: 'The id of a worker run of the project, from the run list.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new SuccessResponse(200, 'The run, its series and its commands.', WorkerRunShowResponse::class),
        new ProblemResponse(404, ['project_not_found', 'run_not_found', 'route_not_found']),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/runs/{run}',
    name: 'api_worker_run_show',
    requirements: ['run' => WorkerRunApiRoutes::RUN],
    methods: ['GET'],
)]
final class ShowWorkerRunController extends AppController
{
    public function __construct(
        private readonly WorkerRunLookup $lookup,
        private readonly ShowWorkerRunSeriesHandler $showSeries,
        private readonly ListWorkerRunReadingsHandler $listReadings,
        private readonly WorkerRunApiPayload $payload,
        private readonly WorkerRunApiProblems $problems,
    ) {
    }

    public function __invoke(#[ReachableProject] Project $project, string $run): JsonResponse
    {
        $found = $this->lookup->find($project, $run) ?? throw $this->problems->runNotFound();
        $view = ($this->showSeries)(new ShowWorkerRunSeriesCommand($found));
        $readings = ($this->listReadings)(new ListWorkerRunReadingsCommand($project, $view->runs));

        $pending = [];
        foreach ($view->commands as $command) {
            if (BridgeCommandState::Pending === $command->state && null !== $command->workerRun) {
                $pending[(string) $command->workerRun->id] = $command;
            }
        }

        return new JsonResponse([
            'run' => $this->payload->forDetail($found, $pending[(string) $found->id] ?? null, $view->stateChanges[(string) $found->id] ?? [], $readings),
            'series' => array_map(fn (WorkerRun $member): array => $this->payload->forRow($member, $pending[(string) $member->id] ?? null, $readings), $view->runs),
            'commands' => array_map($this->payload->forCommand(...), $view->commands),
        ]);
    }
}
