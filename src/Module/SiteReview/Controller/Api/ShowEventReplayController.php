<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller\Api;

use App\Api\Problem\ApiProblem;
use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Service\BridgeUpgradeRequired;
use App\Module\Bridge\Service\EventStreamGate;
use App\Module\SiteReview\Command\ShowEventReplayCommand;
use App\Module\SiteReview\Command\ShowEventReplayHandler;
use App\Outbox\AgentPush;
use App\Outbox\Entity\OutboxEvent;
use App\Security\CredentialRateLimitKey;
use OpenApi\Attributes as OA;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\RateLimit;
use Symfony\Component\Routing\Attribute\Route;
use Ubermuda\FeatureFlagsBundle\Attribute\RequireFeatureFlag;

/**
 * Hands the bridge CLI the outbox events of the caller's projects after its
 * cursor, in the shape the hub delivers them, so it can catch up on what it
 * missed while it was stopped or reconnecting. The firewall admits
 * agent-scoped tokens alone.
 *
 * The rate limit key expression sees only the request, the arguments and this
 * controller, so the key service rides on a public property.
 */
#[OA\Get(
    operationId: 'replayBridgeEvents',
    summary: 'Read the outbox events after a cursor',
    tags: ['Bridge'],
    parameters: [
        new OA\Parameter(name: 'X-Loupe-Bridge', description: 'The id of the calling bridge. A missing or unknown value answers 426.', in: 'header', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'after', description: 'The sequence to read after.', in: 'query', required: true, schema: new OA\Schema(type: 'integer', minimum: 0)),
    ],
    responses: [
        new OA\Response(response: 200, description: 'At most 200 new events, with an overlap.', content: new OA\JsonContent(type: 'object')),
        new OA\Response(response: 400, description: 'Refused: `invalid_query`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 401, description: 'Refused: `invalid_credentials`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 402, description: 'Refused: `subscription_required`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 403, description: 'Refused: `credential_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 404, description: 'Refused: `route_not_found`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 405, description: 'Refused: `method_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 426, description: 'Refused: `no_bridge_id`, `unknown_bridge`, `no_work_requests`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 429, description: 'Refused: `rate_limited`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    ],
)]
#[RateLimit('agent_event_replay', key: new Expression('this.rateLimitKey.forRequest(request)'))]
#[RequireFeatureFlag(AgentPush::FLAG)]
#[Route(
    '/api/v1/bridge/events/replay',
    name: 'api_event_replay',
    methods: ['GET'],
)]
#[Route(
    '/api/events/replay',
    name: 'api_legacy_event_replay',
    methods: ['GET'],
)]
final class ShowEventReplayController extends AppController
{
    public function __construct(
        private readonly ShowEventReplayHandler $showEventReplay,
        public readonly CredentialRateLimitKey $rateLimitKey,
    ) {
    }

    public function __invoke(
        Request $request,
        #[MapQueryParameter(options: ['min_range' => 0], validationFailedStatusCode: 400)]
        int $after,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Event replay endpoint reached without an authenticated User.');
        }

        try {
            $view = ($this->showEventReplay)(new ShowEventReplayCommand($user, $request->headers->get(EventStreamGate::HEADER), $after));
        } catch (BridgeUpgradeRequired $e) {
            throw new ApiProblemException(new ApiProblem(Response::HTTP_UPGRADE_REQUIRED, $e->reason, 'Upgrade required', $e->getMessage(), legacyError: $e->getMessage()));
        }

        return new JsonResponse([
            'events' => array_map(
                static fn (OutboxEvent $event): array => [
                    'id' => $event->sequence ?? throw new \LogicException('Outbox event has no sequence.'),
                    'type' => $event->type,
                    'data' => $event->payload,
                ],
                $view->events,
            ),
            'hasMore' => $view->hasMore,
        ]);
    }
}
