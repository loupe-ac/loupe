<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\ReportToolCallsCommand;
use App\Module\Bridge\Command\ReportToolCallsHandler;
use App\Outbox\AgentPush;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Ubermuda\FeatureFlagsBundle\Attribute\RequireFeatureFlag;

/**
 * Records one batch of the tool calls of one worker run. The run id in the
 * path is the key the bridge gave the run. The firewall admits agent-scoped
 * tokens alone.
 *
 * The bridge reads a 404 with no error code as a server with no such endpoint,
 * so every refusal the controller makes carries a code.
 */
#[OA\Put(
    operationId: 'reportWorkerRunToolCalls',
    summary: 'Record one batch of tool calls of a run',
    tags: ['Worker runs'],
    parameters: [
        new OA\Parameter(name: 'handle', description: 'A project id or a project slug.', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'runId', description: 'The key the bridge gave the run. A value that is not a uuid answers 422 `invalid_run_id`.', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'The batch is taken.', content: new OA\JsonContent(type: 'object')),
        new OA\Response(response: 400, description: 'Refused: `invalid_json`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 401, description: 'Refused: `invalid_credentials`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 402, description: 'Refused: `subscription_required`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 403, description: 'Refused: `credential_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 404, description: 'Refused: `project_not_found`, `run_not_found`, `route_not_found`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 405, description: 'Refused: `method_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 415, description: 'Refused: `unsupported_media_type`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 422, description: 'Refused: `invalid_run_id`, `validation_failed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 429, description: 'Refused: `rate_limited`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    ],
)]
#[RequireFeatureFlag(AgentPush::FLAG)]
#[Route(
    '/api/v1/bridge/projects/{handle}/worker-runs/{runId}/tool-calls',
    name: 'api_project_worker_run_tool_calls_report',
    requirements: ['handle' => '[^/]+', 'runId' => '[^/]+'],
    methods: ['PUT'],
)]
#[Route(
    '/api/projects/{handle}/worker-runs/{runId}/tool-calls',
    name: 'api_legacy_project_worker_run_tool_calls_report',
    requirements: ['handle' => '[^/]+', 'runId' => '[^/]+'],
    methods: ['PUT'],
)]
final class ReportToolCallsController extends AppController
{
    public function __construct(
        private readonly ReportToolCallsHandler $reportToolCalls,
    ) {
    }

    public function __invoke(string $handle, string $runId, #[MapRequestPayload] ReportToolCallsRequest $payload): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Tool call endpoint reached without an authenticated User.');
        }

        if (!Uuid::isValid($runId)) {
            throw ApiProblemException::refused(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'invalid_run_id', 'The run id is not a valid id.');
        }

        $result = ($this->reportToolCalls)(new ReportToolCallsCommand(
            owner: $user,
            handle: $handle,
            runKey: Uuid::fromString($runId),
            calls: $payload->calls(),
            timing: $payload->timing(),
        ));

        if (!$result->projectFound) {
            throw ApiProblemException::refused(JsonResponse::HTTP_NOT_FOUND, 'project_not_found', 'No project answers this request.');
        }

        if (!$result->runFound) {
            throw ApiProblemException::refused(JsonResponse::HTTP_NOT_FOUND, 'run_not_found', 'No run answers this request.');
        }

        return $this->json(['stored' => $result->stored]);
    }
}
