<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\ClaimWorkRequestCommand;
use App\Module\Bridge\Command\ClaimWorkRequestHandler;
use App\Module\Bridge\Service\WorkRequestPayload;
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
 * Claims a work request for one of the caller's bridges, on the account named
 * in the body. The firewall admits agent-scoped tokens alone. The answer carries the claim token, and the
 * bridge sends it back with the result.
 *
 * The bridge reads a 404 with no error code as a server with no such endpoint,
 * so every refusal the controller makes carries a code.
 */
#[OA\Post(
    operationId: 'claimWorkRequest',
    summary: 'Claim a work request',
    tags: ['Bridge'],
    parameters: [
        new OA\Parameter(name: 'bridgeId', description: 'The uuid the bridge generates on its first start. Any other value answers 404.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'workRequestId', description: 'The work request id.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'The bridge holds the claim.', content: new OA\JsonContent(type: 'object')),
        new OA\Response(response: 401, description: 'Refused: `invalid_credentials`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 402, description: 'Refused: `subscription_required`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 403, description: 'Refused: `credential_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 404, description: 'Refused: `work_request_not_found`, `route_not_found`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 405, description: 'Refused: `method_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 409, description: 'Refused: `already_claimed`, `card_held`, `project_held`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 422, description: 'Refused: `unknown_bridge`, `capability_missing`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 429, description: 'Refused: `rate_limited`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    ],
)]
#[RateLimit('agent_work_request_claims', key: new Expression('this.rateLimitKey.forRequest(request)'))]
#[RequireFeatureFlag(AgentPush::FLAG)]
#[Route(
    '/api/v1/bridge/bridges/{bridgeId}/work-requests/{workRequestId}/claim',
    name: 'api_bridge_work_request_claim',
    requirements: ['bridgeId' => Requirement::UUID, 'workRequestId' => Requirement::UUID],
    methods: ['POST'],
)]
#[Route(
    '/api/bridges/{bridgeId}/work-requests/{workRequestId}/claim',
    name: 'api_legacy_bridge_work_request_claim',
    requirements: ['bridgeId' => Requirement::UUID, 'workRequestId' => Requirement::UUID],
    methods: ['POST'],
)]
final class ClaimWorkRequestController extends AppController
{
    public function __construct(
        private readonly ClaimWorkRequestHandler $claimWorkRequest,
        public readonly CredentialRateLimitKey $rateLimitKey,
    ) {
    }

    /** An empty body gives a null payload, which the claim refuses as a claim with no account. */
    public function __invoke(
        string $bridgeId,
        string $workRequestId,
        #[MapRequestPayload] ?ClaimWorkRequestRequest $payload = null,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Work request claim endpoint reached without an authenticated User.');
        }

        $result = ($this->claimWorkRequest)(new ClaimWorkRequestCommand(
            owner: $user,
            bridgeId: Uuid::fromString($bridgeId),
            workRequestId: Uuid::fromString($workRequestId),
            account: $payload?->account(),
        ));

        $request = $result->request;
        if (null === $request) {
            $refusal = $result->refusal ?? throw new \LogicException('A claim with no request carries a refusal.');

            throw ApiProblemException::refused($refusal->httpStatus(), $refusal->code(), 'The work request cannot be claimed.');
        }

        return $this->json([
            'workRequestId' => (string) $request->id,
            'claimToken' => (string) $request->claimToken,
            'leaseUntil' => $request->leaseUntil?->format(\DateTimeInterface::ATOM),
            'workRequest' => WorkRequestPayload::of($request),
        ]);
    }
}
