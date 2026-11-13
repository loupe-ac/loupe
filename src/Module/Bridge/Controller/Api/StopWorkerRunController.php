<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicPost;
use App\Api\OpenApi\SuccessResponse;
use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\CloseInteractiveRunCommand;
use App\Module\Bridge\Command\CloseInteractiveRunHandler;
use App\Module\Bridge\Command\RequestBridgeCommandCommand;
use App\Module\Bridge\Command\RequestBridgeCommandHandler;
use App\Module\Bridge\Service\WorkerRunLookup;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Security\ReachableProject;
use App\Security\CurrentApiKeyActor;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[PublicPost(
    operationId: 'stopWorkerRun',
    summary: 'Stop a worker run',
    tags: ['Worker runs'],
    description: 'Asks the bridge of a queued, resumed, preparing or running run to stop it. The command waits for the bridge, which stops the run when it reads the command, and the run list then shows it as `pendingCommand`. A stop ends this run only. It does not make the card unmanaged, so a bridge rule can still start new work on it. PUT the card hold for that. An interactive session closes in Loupe at once and answers `closed` with no `commandId`, because no bridge holds it. A stop by an API key records the key on the command, and on the closed session.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'run', description: 'The id of a worker run of the project, from the run list.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: new Model(type: StopWorkerRunRequest::class))),
    responses: [
        new SuccessResponse(200, 'The stop is requested, or the session is closed.', StopWorkerRunResponse::class),
        new ProblemResponse(400, ['invalid_json']),
        new ProblemResponse(404, ['project_not_found', 'run_not_found', 'route_not_found']),
        new ProblemResponse(409, ['run_not_stoppable', 'run_not_controllable', 'no_bridge', 'unknown_bridge', 'bridge_outdated', 'command_pending']),
        new ProblemResponse(415, ['unsupported_media_type']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/runs/{run}/stop',
    name: 'api_worker_run_stop',
    requirements: ['run' => WorkerRunApiRoutes::RUN],
    methods: ['POST'],
)]
final class StopWorkerRunController extends AppController
{
    public function __construct(
        private readonly WorkerRunLookup $lookup,
        private readonly RequestBridgeCommandHandler $requestCommand,
        private readonly CloseInteractiveRunHandler $closeInteractiveRun,
        private readonly CurrentApiKeyActor $currentKey,
        private readonly WorkerRunApiProblems $problems,
    ) {
    }

    public function __invoke(#[ReachableProject] Project $project, string $run, #[MapRequestPayload] ?StopWorkerRunRequest $body = null): JsonResponse
    {
        $found = $this->lookup->find($project, $run) ?? throw $this->problems->runNotFound();
        $user = $this->getUser() instanceof User ? $this->getUser() : throw new \LogicException('The route needs a signed-in account.');
        $key = $this->currentKey->current();

        if (WorkerRunKind::Interactive === $found->kind) {
            $closed = ($this->closeInteractiveRun)(new CloseInteractiveRunCommand($project, $found->id ?? throw new \LogicException('A stored run has an id.'), $key));
            if (null === $closed) {
                throw $this->problems->runNotFound();
            }

            return new JsonResponse(new StopWorkerRunResponse('closed', null));
        }

        try {
            $command = ($this->requestCommand)(new RequestBridgeCommandCommand(run: $found, kind: BridgeCommandKind::StopRun, requestedBy: $user, reason: $body?->reason, apiKey: $key));
        } catch (DomainErrors $e) {
            throw $this->problems->from($e);
        }

        return new JsonResponse(new StopWorkerRunResponse('stopped', (string) $command->id));
    }
}
