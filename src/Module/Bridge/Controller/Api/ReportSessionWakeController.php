<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Api\OpenApi\RateLimitedResponse;
use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\ReportSessionWakeCommand;
use App\Module\Bridge\Command\ReportSessionWakeHandler;
use App\Outbox\AgentPush;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Ubermuda\FeatureFlagsBundle\Attribute\RequireFeatureFlag;

/**
 * Fills the wake of each run of a session that ended before the bridge reported wakes. A run
 * that holds a wake keeps it. The firewall admits agent-scoped tokens alone.
 *
 * The bridge reads a 404 with no error code as a server with no such endpoint, so every
 * refusal the controller makes carries a code.
 */
#[OA\Put(
    operationId: 'reportWorkerSessionWake',
    summary: 'Record the wakes of a session',
    tags: ['Worker runs'],
    parameters: [
        new OA\Parameter(name: 'handle', description: 'A project id or a project slug.', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'sessionId', description: 'The session id. A value that is not a uuid answers 422 `invalid_session_id`.', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 204, description: 'The wakes are taken, also when no run needed one.'),
        new OA\Response(response: 400, description: 'Refused: `invalid_json`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 401, description: 'Refused: `invalid_credentials`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 402, description: 'Refused: `subscription_required`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 403, description: 'Refused: `credential_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 404, description: 'Refused: `project_not_found`, `route_not_found`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 405, description: 'Refused: `method_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 409, description: 'Refused: `process_count_mismatch`, `ambiguous_start_order`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 415, description: 'Refused: `unsupported_media_type`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 422, description: 'Refused: `invalid_session_id`, `validation_failed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new RateLimitedResponse(),
    ],
)]
#[RequireFeatureFlag(AgentPush::FLAG)]
#[Route(
    '/api/v1/bridge/projects/{handle}/worker-runs/sessions/{sessionId}/wake',
    name: 'api_project_worker_run_session_wake_report',
    requirements: ['handle' => '[^/]+', 'sessionId' => '[^/]+'],
    methods: ['PUT'],
)]
final class ReportSessionWakeController extends AppController
{
    public function __construct(
        private readonly ReportSessionWakeHandler $reportSessionWake,
    ) {
    }

    public function __invoke(string $handle, string $sessionId, #[MapRequestPayload] ReportSessionWakeRequest $payload): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Session wake endpoint reached without an authenticated User.');
        }

        if (!Uuid::isValid($sessionId)) {
            throw ApiProblemException::refused(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'invalid_session_id', 'The session id is not a valid id.');
        }

        $result = ($this->reportSessionWake)(new ReportSessionWakeCommand(
            owner: $user,
            handle: $handle,
            sessionId: Uuid::fromString($sessionId),
            wakes: $payload->wakes(),
        ));

        if (null === $result->runs) {
            throw ApiProblemException::refused(JsonResponse::HTTP_NOT_FOUND, 'project_not_found', 'No project answers this request.');
        }

        if ($result->runs !== $result->wakes) {
            throw ApiProblemException::refused(JsonResponse::HTTP_CONFLICT, 'process_count_mismatch', 'The process count does not match the session.');
        }

        if ($result->ambiguousOrder) {
            throw ApiProblemException::refused(JsonResponse::HTTP_CONFLICT, 'ambiguous_start_order', 'The start order of the processes is ambiguous.');
        }

        if ($result->lastTurnAfterStart) {
            throw ApiProblemException::validationFailed([['field' => 'wakes', 'code' => 'invalid', 'message' => 'The last turn of a session cannot come after the start of its run.']]);
        }

        return new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
    }
}
