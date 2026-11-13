<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicDelete;
use App\Api\OpenApi\SuccessResponse;
use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\CancelBridgeCommandCommand;
use App\Module\Bridge\Command\CancelBridgeCommandHandler;
use App\Module\Bridge\Service\WorkerRunLookup;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Security\ReachableProject;
use App\Security\CurrentApiKeyActor;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[PublicDelete(
    operationId: 'cancelWorkerRunCommand',
    summary: 'Withdraw the command that waits on a worker run',
    tags: ['Worker runs'],
    description: 'Withdraws the resume or stop command that waits on the run, before its bridge reads it. A cancel never changes the hold of the card. A cancel by an API key records the key on the command, apart from the person or key that requested it.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'run', description: 'The id of a worker run of the project, from the run list.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new SuccessResponse(200, 'The command is withdrawn.', WorkerRunCommandResponse::class),
        new ProblemResponse(404, ['project_not_found', 'run_not_found', 'route_not_found']),
        new ProblemResponse(409, ['nothing_pending']),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/runs/{run}/pending-command',
    name: 'api_worker_run_command_cancel',
    requirements: ['run' => WorkerRunApiRoutes::RUN],
    methods: ['DELETE'],
)]
final class CancelWorkerRunCommandController extends AppController
{
    public function __construct(
        private readonly WorkerRunLookup $lookup,
        private readonly CancelBridgeCommandHandler $cancelCommand,
        private readonly CurrentApiKeyActor $currentKey,
        private readonly WorkerRunApiProblems $problems,
    ) {
    }

    public function __invoke(#[ReachableProject] Project $project, string $run): JsonResponse
    {
        $found = $this->lookup->find($project, $run) ?? throw $this->problems->runNotFound();
        $user = $this->getUser() instanceof User ? $this->getUser() : throw new \LogicException('The route needs a signed-in account.');

        try {
            $command = ($this->cancelCommand)(new CancelBridgeCommandCommand($found, $user, apiKey: $this->currentKey->current()));
        } catch (DomainErrors $e) {
            throw $this->problems->from($e);
        }

        return new JsonResponse(new WorkerRunCommandResponse((string) $command->id));
    }
}
