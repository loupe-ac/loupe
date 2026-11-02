<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\ListCardHoldsCommand;
use App\Module\Bridge\Command\ListCardHoldsHandler;
use App\Module\Bridge\Entity\CardHold;
use App\Security\CredentialRateLimitKey;
use OpenApi\Attributes as OA;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\RateLimit;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Hands the bridge CLI the held cards of the caller's projects. The firewall
 * admits agent-scoped tokens alone. No feature flag gates the route, because
 * the bridge reads a 404 as an older server that has no held list.
 *
 * The rate limit key expression sees only the request, the arguments and this
 * controller, so the key service rides on a public property.
 */
#[OA\Get(
    operationId: 'listBridgeCardHolds',
    summary: 'List the held cards of the projects of the caller',
    tags: ['Bridge'],
    responses: [
        new OA\Response(response: 200, description: 'The held cards.', content: new OA\JsonContent(type: 'object')),
        new OA\Response(response: 401, description: 'Refused: `invalid_credentials`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 402, description: 'Refused: `subscription_required`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 403, description: 'Refused: `credential_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 404, description: 'Refused: `route_not_found`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 405, description: 'Refused: `method_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 429, description: 'Refused: `rate_limited`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    ],
)]
#[RateLimit('agent_card_holds', key: new Expression('this.rateLimitKey.forRequest(request)'))]
#[Route(
    '/api/v1/bridge/card-holds',
    name: 'api_card_holds',
    methods: ['GET'],
)]
#[Route(
    '/api/card-holds',
    name: 'api_legacy_card_holds',
    methods: ['GET'],
)]
final class ListCardHoldsController extends AppController
{
    public function __construct(
        private readonly ListCardHoldsHandler $listCardHolds,
        public readonly CredentialRateLimitKey $rateLimitKey,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Card hold list reached without an authenticated User.');
        }

        $view = ($this->listCardHolds)(new ListCardHoldsCommand($user));

        return new JsonResponse([
            'holds' => array_map(
                static fn (CardHold $hold): array => [
                    'projectId' => ($hold->project->id ?? throw new \LogicException('A stored project has an id.'))->toRfc4122(),
                    'cardId' => $hold->cardId->toRfc4122(),
                ],
                $view->holds,
            ),
        ]);
    }
}
