<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\AcknowledgeBridgeCommandCommand;
use App\Module\Bridge\Command\AcknowledgeBridgeCommandHandler;
use App\Outbox\AgentPush;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;
use Ubermuda\FeatureFlagsBundle\Attribute\RequireFeatureFlag;

/**
 * Records the answer of a bridge to one of its commands. The firewall admits
 * agent-scoped tokens alone.
 *
 * The bridge reads a 404 with no error code as a server with no such endpoint,
 * so every refusal the controller makes carries a code.
 */
#[OA\Put(
    operationId: 'acknowledgeBridgeCommand',
    summary: 'Record the answer of a bridge to a command',
    tags: ['Bridge'],
    parameters: [
        new OA\Parameter(name: 'bridgeId', description: 'The uuid the bridge generates on its first start. Any other value answers 404.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'commandId', description: 'The command id.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'The answer is accepted.', content: new OA\JsonContent(type: 'object')),
        new OA\Response(response: 400, description: 'Refused: `invalid_json`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 401, description: 'Refused: `invalid_credentials`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 402, description: 'Refused: `subscription_required`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 403, description: 'Refused: `credential_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 404, description: 'Refused: `command_not_found`, `route_not_found`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 405, description: 'Refused: `method_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 415, description: 'Refused: `unsupported_media_type`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 422, description: 'Refused: `invalid_state`, `invalid_reason`, `reason_too_long`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 429, description: 'Refused: `rate_limited`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    ],
)]
#[RequireFeatureFlag(AgentPush::FLAG)]
#[Route(
    '/api/v1/bridge/bridges/{bridgeId}/commands/{commandId}',
    name: 'api_bridge_command_ack',
    requirements: ['bridgeId' => Requirement::UUID, 'commandId' => Requirement::UUID],
    methods: ['PUT'],
)]
#[Route(
    '/api/bridges/{bridgeId}/commands/{commandId}',
    name: 'api_legacy_bridge_command_ack',
    requirements: ['bridgeId' => Requirement::UUID, 'commandId' => Requirement::UUID],
    methods: ['PUT'],
)]
final class AcknowledgeBridgeCommandController extends AppController
{
    public function __construct(
        private readonly AcknowledgeBridgeCommandHandler $acknowledgeBridgeCommand,
    ) {
    }

    /** An empty body gives a null payload, which the controller refuses with its own code. */
    public function __invoke(
        string $bridgeId,
        string $commandId,
        #[MapRequestPayload] ?AcknowledgeBridgeCommandRequest $payload = null,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Bridge command ack endpoint reached without an authenticated User.');
        }

        $state = $payload?->state();
        if (null === $state) {
            throw ApiProblemException::refused(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'invalid_state', 'The state is not one this route takes.');
        }

        if (!$payload->hasTextReason()) {
            throw ApiProblemException::refused(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'invalid_reason', 'The reason is not valid.');
        }

        if ($payload->reasonTooLong()) {
            throw ApiProblemException::refused(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'reason_too_long', 'The reason is too long.');
        }

        $result = ($this->acknowledgeBridgeCommand)(new AcknowledgeBridgeCommandCommand(
            owner: $user,
            bridgeId: Uuid::fromString($bridgeId),
            commandId: Uuid::fromString($commandId),
            state: $state,
            reason: $payload->reason(),
        ));

        if (null === $result->command) {
            throw ApiProblemException::refused(JsonResponse::HTTP_NOT_FOUND, 'command_not_found', 'No command answers this request.');
        }

        return $this->json(['commandId' => (string) $result->command->id, 'state' => $result->command->state->value]);
    }
}
