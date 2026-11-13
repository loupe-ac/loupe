<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicPost;
use App\Api\OpenApi\SuccessResponse;
use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\ResumeWorkerRunsCommand;
use App\Module\Bridge\Command\ResumeWorkerRunsEntry;
use App\Module\Bridge\Command\ResumeWorkerRunsHandler;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Service\WorkerRunLookup;
use App\Module\Bridge\ValueObject\BridgeCommandCause;
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
    operationId: 'resumeWorkerRuns',
    summary: 'Resume up to 50 worker runs',
    tags: ['Worker runs'],
    description: 'Asks the bridge of each run to resume it, each run on its own and in order, so a refused run does not stop the others. The answer is 200 with one row per run id, in the order of the request, so read each row: the status does not show the outcome of a run. A run of another project gets the row `not-found`, as a missing run does. An unexpected failure on a run gives the row `error`, whose outcome is unknown, and the route then stops: each later row is `not-attempted`. Send those runs again. A resume by an API key records the key on each command.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
    ],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: ResumeWorkerRunsRequest::class))),
    responses: [
        new SuccessResponse(200, 'One row per run.', ResumeWorkerRunsResponse::class),
        new ProblemResponse(400, ['invalid_json']),
        new ProblemResponse(404, ['project_not_found', 'route_not_found']),
        new ProblemResponse(415, ['unsupported_media_type']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/runs/resume',
    name: 'api_worker_run_resume_batch',
    methods: ['POST'],
)]
final class ResumeWorkerRunsController extends AppController
{
    public function __construct(
        private readonly WorkerRunLookup $lookup,
        private readonly ResumeWorkerRunsHandler $resumeRuns,
        private readonly CurrentApiKeyActor $currentKey,
    ) {
    }

    public function __invoke(#[ReachableProject] Project $project, #[MapRequestPayload] ResumeWorkerRunsRequest $body): JsonResponse
    {
        $user = $this->getUser() instanceof User ? $this->getUser() : throw new \LogicException('The route needs a signed-in account.');
        $key = $this->currentKey->current();

        $entries = array_map(
            fn (string $runId): ResumeWorkerRunsEntry => new ResumeWorkerRunsEntry($runId, fn (): ?WorkerRun => $this->lookup->find($project, $runId)),
            $body->runIds,
        );
        if ([] === $entries) {
            throw new \LogicException('runIds holds at least one id after validation');
        }

        $view = ($this->resumeRuns)(new ResumeWorkerRunsCommand($user, null === $key ? BridgeCommandCause::Person : BridgeCommandCause::ApiKey, $entries, $key));

        return new JsonResponse(new ResumeWorkerRunsResponse($view->results));
    }
}
