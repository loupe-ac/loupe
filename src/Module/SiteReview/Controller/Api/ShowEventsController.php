<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller\Api;

use App\Api\Problem\ApiProblem;
use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Service\BridgeUpgradeRequired;
use App\Module\Bridge\Service\CliCompatibility;
use App\Module\Bridge\Service\EventStreamGate;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Command\ShowEventsCommand;
use App\Module\SiteReview\Command\ShowEventsHandler;
use App\Outbox\AgentPush;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Ubermuda\FeatureFlagsBundle\Attribute\RequireFeatureFlag;

/**
 * Hands the bridge CLI what it needs to follow the events of every project its
 * user owns: the public hub URL, the user's own topic, a subscriber JWT for that
 * one topic, and the projects whose events arrive on it.
 *
 * Agent-scoped tokens only. The firewall grants this path to ROLE_API_AGENT
 * alone, so a project-bound widget token gets 403 `insufficient_scope` before
 * this class runs. A widget token is embedded in public page HTML, and letting
 * one mint subscriber JWTs would let any page visitor watch the owner's streams.
 */
// 404 rather than a disabled-looking 403: with push off there is no hub to
// subscribe to, so there is nothing here to be authorized for.
#[OA\Get(
    operationId: 'getBridgeEvents',
    summary: 'Read what the bridge needs to follow events',
    tags: ['Bridge'],
    parameters: [
        new OA\Parameter(name: 'X-Loupe-Bridge', description: 'The id of the calling bridge. A missing or unknown value answers 426.', in: 'header', schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'The hub, the topic, the projects and the flags.', content: new OA\JsonContent(ref: '#/components/schemas/EventsAnswer')),
        new OA\Response(response: 401, description: 'Refused: `invalid_credentials`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 402, description: 'Refused: `subscription_required`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 403, description: 'Refused: `credential_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 404, description: 'Refused: `route_not_found`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 405, description: 'Refused: `method_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 426, description: 'Refused: `no_bridge_id`, `unknown_bridge`, `no_work_requests`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 429, description: 'Refused: `rate_limited`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    ],
)]
#[RequireFeatureFlag(AgentPush::FLAG)]
#[Route(
    '/api/v1/bridge/events',
    name: 'api_events',
    methods: ['GET'],
)]
#[Route(
    '/api/events',
    name: 'api_legacy_events',
    methods: ['GET'],
)]
final class ShowEventsController extends AppController
{
    public function __construct(
        private readonly ShowEventsHandler $showEvents,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Events endpoint reached without an authenticated User.');
        }

        try {
            $view = ($this->showEvents)(new ShowEventsCommand($user, $request->headers->get(EventStreamGate::HEADER)));
        } catch (BridgeUpgradeRequired $e) {
            throw new ApiProblemException(new ApiProblem(Response::HTTP_UPGRADE_REQUIRED, $e->reason, 'Upgrade required', $e->getMessage(), legacyError: $e->getMessage()));
        }

        return $this->json([
            'hubUrl' => $view->hubUrl,
            'jwt' => $view->jwt,
            'topic' => $view->topic,
            'projects' => array_map(
                static fn (Project $project): array => [
                    'id' => (string) $project->id,
                    'slug' => $project->slug,
                    'name' => $project->name,
                ],
                $view->projects,
            ),
            // An empty map must encode as {}, and the serializer writes [] for an
            // empty object unless it is told to preserve it.
            'flags' => (object) $view->flags,
            'cliRange' => CliCompatibility::RANGE,
            'head' => $view->head,
        ], context: [AbstractObjectNormalizer::PRESERVE_EMPTY_OBJECTS => true]);
    }
}
