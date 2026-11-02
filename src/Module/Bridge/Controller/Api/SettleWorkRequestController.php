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
