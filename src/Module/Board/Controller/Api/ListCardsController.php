<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use App\Module\Board\Command\SearchCardsCommand;
use App\Module\Board\Command\SearchCardsHandler;
use App\Module\Board\Entity\Card;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\Workflow\Contract\CardTypeCatalog;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lists the project's open cards for the widget's picker.
 *
 * This publishes card titles to any holder of the widget token, which is
 * everyone who can read an instrumented page's source. That is accepted rather
 * than overlooked; the Loupe design 'Picking and creating cards from the
 * site-review widget' records the decision and what it costs.
 */
#[OA\Get(
    operationId: 'listWidgetCards',
    summary: 'List the open cards for the widget picker',
    tags: ['Widget'],
    parameters: [
        new OA\Parameter(name: 'X-Loupe-Project', description: 'The project id this request acts on, for a credential that covers several projects. A credential bound to one project may only confirm it.', in: 'header', schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'q', description: 'Words to search for.', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'type', description: 'A type key. An unknown key means every type.', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'parent', description: 'True lists cards of types that can have children.', in: 'query', schema: new OA\Schema(type: 'boolean')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'At most 20 cards.', content: new OA\JsonContent(type: 'object', additionalProperties: true)),
        new OA\Response(response: 401, description: 'Refused: `invalid_credentials`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 402, description: 'Refused: `subscription_required`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 403, description: 'Refused: `token_not_bound_to_site`, `credential_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 404, description: 'Refused: `route_not_found`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 405, description: 'Refused: `method_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 429, description: 'Refused: `rate_limited`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    ],
)]
#[Route(
    '/api/v1/live-review-widget/cards',
    name: 'api_board_card_list',
    methods: ['GET'],
)]
#[Route(
    '/api/board/cards',
    name: 'api_legacy_board_card_list',
    methods: ['GET'],
)]
final class ListCardsController extends AppController
{
    /** One page of cards. A picker is for choosing, not for browsing a backlog. */
    private const int LIMIT = 20;

    public function __construct(
        private readonly SearchCardsHandler $handler,
        private readonly AuthenticatedProjectResolver $projectResolver,
        private readonly CardTypeCatalog $catalog,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $project = $this->projectResolver->resolveWidgetProject();
        if (null === $project) {
            throw ApiProblemException::refused(JsonResponse::HTTP_FORBIDDEN, 'token_not_bound_to_site', 'The token is not bound to a site.');
        }

        $known = $this->catalog->forProject($project->requireId());
        $query = $request->query->get('q');
        $type = $request->query->get('type');
        $types = \is_string($type) && $known->has($type) ? [$type] : [];
        if ($request->query->getBoolean('parent')) {
            $types = $known->withChildren();
            // An empty list would mean every type, which is the wrong answer here.
            if ([] === $types) {
                return $this->json(['cards' => []]);
            }
        }

        $view = ($this->handler)(new SearchCardsCommand(
            $project,
            \is_string($query) ? trim($query) : '',
            self::LIMIT,
            $types,
        ));

        return $this->json(['cards' => array_map(
            static fn (Card $card): array => [
                'cardId' => (string) $card->id,
                'number' => $card->number,
                'title' => $card->title,
                'status' => $card->column->slug,
            ],
            $view->cards,
        )]);
    }
}
