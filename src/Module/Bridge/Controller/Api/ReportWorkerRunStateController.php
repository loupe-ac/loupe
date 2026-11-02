<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\ReportWorkerRunStateCommand;
use App\Module\Bridge\Command\ReportWorkerRunStateHandler;
use App\Outbox\AgentPush;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Ubermuda\FeatureFlagsBundle\Attribute\RequireFeatureFlag;

/**
 * Records one state of one worker run against one of the caller's projects.
 * The firewall admits agent-scoped tokens alone.
 *
 * Every refusal the controller makes carries an error code, so the bridge can
 * tell an unknown project from an instance with agent push switched off.
 */
#[OA\Put(
    operationId: 'reportWorkerRunState',
    summary: 'Report one state of a worker run',
    tags: ['Worker runs'],
    parameters: [
        new OA\Parameter(name: 'handle', description: 'A project id or a project slug.', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'runId', description: 'The key the bridge gave the run. A value that is not a uuid answers 422 `invalid_run_id`.', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'The run already held this state.', content: new OA\JsonContent(type: 'object')),
        new OA\Response(response: 201, description: 'The state is new, and the server wrote a history row.', content: new OA\JsonContent(type: 'object')),
        new OA\Response(response: 400, description: 'Refused: `invalid_json`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 401, description: 'Refused: `invalid_credentials`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 402, description: 'Refused: `subscription_required`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 403, description: 'Refused: `credential_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 404, description: 'Refused: `project_not_found`, `route_not_found`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 405, description: 'Refused: `method_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 415, description: 'Refused: `unsupported_media_type`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 422, description: 'Refused: `invalid_run_id`, `validation_failed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 429, description: 'Refused: `rate_limited`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    ],
)]
#[RequireFeatureFlag(AgentPush::FLAG)]
#[Route(
    '/api/v1/bridge/projects/{handle}/worker-runs/{runId}',
    name: 'api_project_worker_run_state_report',
    requirements: ['handle' => '[^/]+', 'runId' => '[^/]+'],
    methods: ['PUT'],
)]
#[Route(
    '/api/projects/{handle}/worker-runs/{runId}',
    name: 'api_legacy_project_worker_run_state_report',
    requirements: ['handle' => '[^/]+', 'runId' => '[^/]+'],
    methods: ['PUT'],
)]
final class ReportWorkerRunStateController extends AppController
{
    public function __construct(
        private readonly ReportWorkerRunStateHandler $reportWorkerRunState,
    ) {
    }

    public function __invoke(string $handle, string $runId, #[MapRequestPayload] ReportWorkerRunStateRequest $payload): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Worker run state endpoint reached without an authenticated User.');
        }

        if (!Uuid::isValid($runId)) {
            throw ApiProblemException::refused(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'invalid_run_id', 'The run id is not a valid id.');
        }

        $result = ($this->reportWorkerRunState)(new ReportWorkerRunStateCommand(
            owner: $user,
            handle: $handle,
            runKey: Uuid::fromString($runId),
            bridgeId: $payload->bridgeId(),
            state: $payload->state(),
            at: $payload->at(),
            subject: $payload->subject(),
            cardNumber: $payload->cardNumber,
            workRequestId: $payload->workRequestId(),
            workKind: $payload->workKind,
            ruleId: $payload->ruleId,
            sessionId: $payload->sessionId(),
            startedAt: $payload->startedAt(),
            endedAt: $payload->endedAt(),
            exitCode: $payload->exitCode,
            hasResult: $payload->hasResult(),
            failureReason: $payload->failureReason(),
            output: $payload->output,
            resultStatus: $payload->resultStatus,
            resultReason: $payload->resultReason(),
            resultFields: $payload->resultFields(),
            continues: $payload->continues(),
            resumeSkipped: $payload->resumeSkipped,
            usage: $payload->usage?->report(),
            workerPool: $payload->workerPool,
            experiment: $payload->experiment,
            variant: $payload->variant,
            requestedModel: $payload->requestedModel,
            switchedFrom: $payload->switchedFrom,
            kind: $payload->kind(),
            harness: $payload->harness,
            account: $payload->account,
            model: $payload->model,
            harnessSessionId: $payload->harnessSessionId,
            harnessVersion: $payload->harnessVersion,
            cliVersion: $payload->cliVersion,
            skillVersion: $payload->skillVersion,
            peakContextTokens: $payload->peakContextTokens,
            harnessEvents: $payload->harnessEvents(),
            costSource: $payload->costSource,
        ));

        if (null === $result->run) {
            throw ApiProblemException::refused(JsonResponse::HTTP_NOT_FOUND, 'project_not_found', 'No project answers this request.');
        }

        return $this->json(
            ['id' => (string) $result->run->id],
            $result->newState ? JsonResponse::HTTP_CREATED : JsonResponse::HTTP_OK,
        );
    }
}
