<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\ReportInteractiveLaunchCommand;
use App\Module\Bridge\Command\ReportInteractiveLaunchHandler;
use App\Module\Bridge\Command\ReportInteractiveLaunchResult;
use App\Outbox\AgentPush;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Ubermuda\FeatureFlagsBundle\Attribute\RequireFeatureFlag;

/**
 * Records how the bridge's launch of one interactive session on a card, or on another subject such as an inbox item, went.
 * The firewall admits agent-scoped tokens alone.
 *
 * The bridge reads a 404 with no error code as a server with no such endpoint,
 * so every refusal the controller makes carries a code.
 */
#[OA\Put(
    operationId: 'reportInteractiveLaunch',
    summary: 'Report how the launch of an interactive session went',
    tags: ['Worker runs'],
    parameters: [
        new OA\Parameter(name: 'handle', description: 'A project id or a project slug.', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'sessionId', description: 'The session id. A value that is not a uuid answers 422 `invalid_session_id`.', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'The run already existed.', content: new OA\JsonContent(type: 'object')),
        new OA\Response(response: 201, description: 'The server created the run.', content: new OA\JsonContent(type: 'object')),
        new OA\Response(response: 400, description: 'Refused: `invalid_json`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 401, description: 'Refused: `invalid_credentials`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 402, description: 'Refused: `subscription_required`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 403, description: 'Refused: `credential_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 404, description: 'Refused: `project_not_found`, `route_not_found`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 405, description: 'Refused: `method_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 415, description: 'Refused: `unsupported_media_type`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 422, description: 'Refused: `invalid_session_id`, `validation_failed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 429, description: 'Refused: `rate_limited`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    ],
)]
#[RequireFeatureFlag(AgentPush::FLAG)]
#[Route(
    '/api/v1/bridge/projects/{handle}/interactive-runs/{sessionId}',
    name: 'api_project_interactive_run_report',
    requirements: ['handle' => '[^/]+', 'sessionId' => '[^/]+'],
    methods: ['PUT'],
)]
#[Route(
    '/api/projects/{handle}/interactive-runs/{sessionId}',
    name: 'api_legacy_project_interactive_run_report',
    requirements: ['handle' => '[^/]+', 'sessionId' => '[^/]+'],
    methods: ['PUT'],
)]
final class ReportInteractiveLaunchController extends AppController
{
    public function __construct(
        private readonly ReportInteractiveLaunchHandler $reportInteractiveLaunch,
    ) {
    }

    public function __invoke(string $handle, string $sessionId, #[MapRequestPayload] ReportInteractiveLaunchRequest $payload): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Interactive launch endpoint reached without an authenticated User.');
        }

        if (!Uuid::isValid($sessionId)) {
            throw ApiProblemException::refused(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'invalid_session_id', 'The session id is not a valid id.');
        }

        $result = ($this->reportInteractiveLaunch)(new ReportInteractiveLaunchCommand(
            owner: $user,
            handle: $handle,
            sessionId: Uuid::fromString($sessionId),
            bridgeId: $payload->bridgeId(),
            subject: $payload->subject(),
            cardNumber: $payload->cardNumber,
            workKind: $payload->workKind(),
            state: $payload->state(),
            at: $payload->at(),
            failureReason: $payload->failureReason(),
            workRequestId: $payload->workRequestId(),
            ruleId: $payload->ruleId,
            harness: $payload->harness,
            account: $payload->account,
            model: $payload->model,
            harnessSessionId: $payload->harnessSessionId,
        ));

        if (null === $result->run) {
            throw ApiProblemException::refused(ReportInteractiveLaunchResult::UNKNOWN_SUBJECT_TYPE === $result->error ? JsonResponse::HTTP_UNPROCESSABLE_ENTITY : JsonResponse::HTTP_NOT_FOUND, $result->error, 'The interactive launch was refused.');
        }

        return $this->json(
            ['id' => (string) $result->run->id],
            $result->created ? JsonResponse::HTTP_CREATED : JsonResponse::HTTP_OK,
        );
    }
}
