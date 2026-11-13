<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicPost;
use App\Api\OpenApi\SuccessResponse;
use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\RequestBridgeCommandCommand;
use App\Module\Bridge\Command\RequestBridgeCommandHandler;
use App\Module\Bridge\Service\WorkerRunLookup;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Security\ReachableProject;
use App\Security\CurrentApiKeyActor;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[PublicPost(
    operationId: 'resumeWorkerRun',
    summary: 'Resume a worker run',
    tags: ['Worker runs'],
    description: 'Asks the bridge of a run to resume it. A resume continues the session of the run as a new run of the same series. The command waits for the bridge, which resumes the run when it reads the command, and the run list then shows it as `pendingCommand`. Only a run in the state blocked, gave-up, failed, no-result, unfinished, timed-out, lost, stopped or waiting-for-person can resume. A resume by an API key records the key on the command. `POST .../runs/resume` resumes up to 50 runs.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'run', description: 'The id of a worker run of the project, from the run list.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new SuccessResponse(200, 'The resume is requested.', WorkerRunCommandResponse::class),
        new ProblemResponse(404, ['project_not_found', 'run_not_found', 'route_not_found']),
        new ProblemResponse(409, ['run_not_resumable', 'run_not_controllable', 'no_session', 'no_bridge', 'unknown_bridge', 'bridge_outdated', 'command_pending', 'card_held']),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/runs/{run}/resume',
    name: 'api_worker_run_resume',
    requirements: ['run' => WorkerRunApiRoutes::RUN],
    methods: ['POST'],
)]
final class ResumeWorkerRunController extends AppController
{
    public function __construct(
        private readonly WorkerRunLookup $lookup,
        private readonly RequestBridgeCommandHandler $requestCommand,
        private readonly CurrentApiKeyActor $currentKey,
        private readonly WorkerRunApiProblems $problems,
    ) {
    }

    public function __invoke(#[ReachableProject] Project $project, string $run): JsonResponse
    {
        $found = $this->lookup->find($project, $run) ?? throw $this->problems->runNotFound();
        $user = $this->getUser() instanceof User ? $this->getUser() : throw new \LogicException('The route needs a signed-in account.');

        try {
            $command = ($this->requestCommand)(new RequestBridgeCommandCommand(run: $found, kind: BridgeCommandKind::ResumeRun, requestedBy: $user, apiKey: $this->currentKey->current()));
        } catch (DomainErrors $e) {
            throw $this->problems->from($e);
        }

        return new JsonResponse(new WorkerRunCommandResponse((string) $command->id));
    }
}
