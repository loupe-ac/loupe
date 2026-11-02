<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\RecordBridgeHeartbeatCommand;
use App\Module\Bridge\Command\RecordBridgeHeartbeatHandler;
use App\Module\Bridge\Service\BridgeCommandPayload;
use App\Module\Bridge\Service\WorkRequestPayload;
use App\Module\Bridge\ValueObject\AccountLimit;
use App\Outbox\AgentPush;
use App\Security\AuthenticatedCredential;
use App\Security\CredentialRateLimitKey;
use OpenApi\Attributes as OA;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Attribute\RateLimit;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Uid\Uuid;
use Ubermuda\FeatureFlagsBundle\Attribute\RequireFeatureFlag;

/**
 * Records that one of the caller's bridges is alive. The firewall admits
 * agent-scoped tokens alone. With push off the answer is a 404, as the events
 * endpoint gives, because a bridge cannot run without push.
 *
 * The rate limit key expression sees only the request, the arguments and this
 * controller, so the key service rides on a public property.
 */
#[OA\Put(
    operationId: 'recordBridgeHeartbeat',
    summary: 'Record that a bridge is alive',
    tags: ['Bridge'],
    parameters: [
        new OA\Parameter(name: 'bridgeId', description: 'The uuid the bridge generates on its first start. Any other value answers 404.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'The heartbeat is accepted.', content: new OA\JsonContent(ref: '#/components/schemas/HeartbeatAnswer')),
        new OA\Response(response: 400, description: 'Refused: `invalid_json`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 401, description: 'Refused: `invalid_credentials`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 402, description: 'Refused: `subscription_required`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 403, description: 'Refused: `credential_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 404, description: 'Refused: `route_not_found`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 405, description: 'Refused: `method_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 415, description: 'Refused: `unsupported_media_type`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 422, description: 'Refused: `validation_failed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 429, description: 'Refused: `rate_limited`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    ],
)]
#[RateLimit('agent_bridge_heartbeats', key: new Expression('this.rateLimitKey.forRequest(request)'))]
#[RequireFeatureFlag(AgentPush::FLAG)]
#[Route(
    '/api/v1/bridge/bridges/{bridgeId}/heartbeat',
    name: 'api_bridge_heartbeat',
    requirements: ['bridgeId' => Requirement::UUID],
    methods: ['PUT'],
)]
#[Route(
    '/api/bridges/{bridgeId}/heartbeat',
    name: 'api_legacy_bridge_heartbeat',
    requirements: ['bridgeId' => Requirement::UUID],
    methods: ['PUT'],
)]
final class RecordBridgeHeartbeatController extends AppController
{
    public function __construct(
        private readonly RecordBridgeHeartbeatHandler $recordHeartbeat,
        public readonly CredentialRateLimitKey $rateLimitKey,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    public function __invoke(string $bridgeId, #[MapRequestPayload] RecordBridgeHeartbeatRequest $payload): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Bridge heartbeat endpoint reached without an authenticated User.');
        }

        $result = ($this->recordHeartbeat)(new RecordBridgeHeartbeatCommand(
            owner: $user,
            bridgeId: Uuid::fromString($bridgeId),
            projects: $payload->projectIds(),
            cliVersion: $payload->cliVersion(),
            updateState: $payload->update?->state(),
            updateVersion: $payload->update?->version,
            hooks: $payload->hooks(),
            workerPools: $payload->workerPools(),
            paused: $payload->paused,
            capabilities: $payload->capabilities(),
            installMethod: $payload->update?->install(),
            workClaims: $payload->workClaims(),
            name: $payload->name(),
            hostSamples: $payload->hostSamples(),
            accounts: $payload->accounts(),
            commandEntries: $payload->commandEntries(),
            worktrees: $payload->worktrees(),
            workKinds: $payload->workKinds(),
            repositories: $payload->repositories(),
            gitIdentities: $payload->gitIdentities(),
            gitIdentitiesAge: $payload->gitIdentitiesAge ?? 0,
            interactiveSessions: $payload->interactiveSessions(),
            accessTokenId: AuthenticatedCredential::of($this->tokenStorage->getToken())?->accessTokenId,
            accountReadings: $payload->accountReadings(),
            skipped: $payload->skipped(),
        ));

        return new JsonResponse([
            'cliRange' => $result->cliRange,
            'paused' => $result->bridge->pauseRequested,
            'commands' => array_map(BridgeCommandPayload::of(...), $result->commands),
            'workRequests' => array_map(WorkRequestPayload::of(...), $result->workRequests),
            'lostClaims' => $result->lostClaims,
            'watchSessions' => $result->watchSessions,
            'blockedAccounts' => array_values(array_map(
                static fn (AccountLimit $limit): array => ['account' => $limit->account, 'until' => $limit->releaseAt()?->format(\DateTimeInterface::ATOM)],
                $result->blockedAccounts,
            )),
        ]);
    }
}
