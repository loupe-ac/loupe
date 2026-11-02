<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\SettleWorkRequestCommand;
use App\Module\Bridge\Command\SettleWorkRequestHandler;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Outbox\AgentPush;
use App\Security\CredentialRateLimitKey;
use OpenApi\Attributes as OA;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Attribute\RateLimit;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;
use Ubermuda\FeatureFlagsBundle\Attribute\RequireFeatureFlag;

/**
 * Records the result of a bridge for a work request it claimed, or takes the
 * request back when the bridge gives it back with the state open. The firewall
 * admits agent-scoped tokens alone.
 *
 * The bridge reads a 404 with no error code as a server with no such endpoint,
 * so every refusal the controller makes carries a code.
 */
#[OA\Put(
    operationId: 'settleWorkRequest',
    summary: 'Send the result of a claimed work request',
    tags: ['Bridge'],
    parameters: [
        new OA\Parameter(name: 'bridgeId', description: 'The uuid the bridge generates on its first start. Any other value answers 404.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'workRequestId', description: 'The work request id.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'The result is accepted.', content: new OA\JsonContent(ref: '#/components/schemas/WorkResultAnswer')),
        new OA\Response(response: 400, description: 'Refused: `invalid_json`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 401, description: 'Refused: `invalid_credentials`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 402, description: 'Refused: `subscription_required`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 403, description: 'Refused: `credential_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 404, description: 'Refused: `work_request_not_found`, `route_not_found`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 405, description: 'Refused: `method_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 409, description: 'Refused: `claim_lost`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 415, description: 'Refused: `unsupported_media_type`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 422, description: 'Refused: `invalid_state`, `invalid_claim_token`, `invalid_reason`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 429, description: 'Refused: `rate_limited`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    ],
)]
#[RateLimit('agent_work_request_results', key: new Expression('this.rateLimitKey.forRequest(request)'))]
#[RequireFeatureFlag(AgentPush::FLAG)]
#[Route(
    '/api/v1/bridge/bridges/{bridgeId}/work-requests/{workRequestId}/result',
    name: 'api_bridge_work_request_result',
    requirements: ['bridgeId' => Requirement::UUID, 'workRequestId' => Requirement::UUID],
    methods: ['PUT'],
)]
#[Route(
    '/api/bridges/{bridgeId}/work-requests/{workRequestId}/result',
    name: 'api_legacy_bridge_work_request_result',
    requirements: ['bridgeId' => Requirement::UUID, 'workRequestId' => Requirement::UUID],
    methods: ['PUT'],
)]
final class SettleWorkRequestController extends AppController
{
    public function __construct(
        private readonly SettleWorkRequestHandler $settleWorkRequest,
        public readonly CredentialRateLimitKey $rateLimitKey,
    ) {
    }

    /** An empty body gives a null payload, which the controller refuses with its own code. */
    public function __invoke(
        string $bridgeId,
        string $workRequestId,
        #[MapRequestPayload] ?SettleWorkRequestRequest $payload = null,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Work request result endpoint reached without an authenticated User.');
        }

        $state = $payload?->state();
        if (null === $state) {
            throw ApiProblemException::refused(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'invalid_state', 'The state is not one this route takes.');
        }

        $claimToken = $payload->claimToken();
        if (null === $claimToken) {
            throw ApiProblemException::refused(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'invalid_claim_token', 'The claim token is not valid.');
        }

        if (!$payload->hasValidReason()) {
            throw ApiProblemException::refused(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'invalid_reason', 'The reason is not valid.');
        }

        $handBack = null;
        if (WorkRequestState::Open === $state) {
            if (!$payload->hasValidCause()) {
                throw ApiProblemException::refused(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'invalid_cause', 'The cause is not one this route takes.');
            }

            $handBack = $payload->handBack();
            if (null === $handBack) {
                throw ApiProblemException::refused(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'invalid_hand_back', 'The hand back is not valid.');
            }
        }

        $result = ($this->settleWorkRequest)(new SettleWorkRequestCommand(
            owner: $user,
            bridgeId: Uuid::fromString($bridgeId),
            workRequestId: Uuid::fromString($workRequestId),
            claimToken: $claimToken,
            state: $state,
            reason: $payload->reason(),
            handBack: $handBack,
        ));

        $request = $result->request;
        if (null === $request) {
            $refusal = $result->refusal ?? throw new \LogicException('A result with no request carries a refusal.');

            throw ApiProblemException::refused($refusal->httpStatus(), $refusal->code(), 'The work request cannot be settled.');
        }

        return $this->json(['workRequestId' => (string) $request->id, 'state' => $request->state->value]);
    }
}
