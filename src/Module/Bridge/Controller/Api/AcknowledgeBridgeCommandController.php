<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\AcknowledgeBridgeCommandCommand;
use App\Module\Bridge\Command\AcknowledgeBridgeCommandHandler;
use App\Outbox\AgentPush;
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
