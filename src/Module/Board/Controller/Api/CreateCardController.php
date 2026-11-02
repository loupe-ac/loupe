<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Controller\AppController;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Entity\CardSource;
use App\Module\Board\Entity\CardSourceKind;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardTypeCatalog;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Creates a board card from the site-review widget.
 *
 * `config/packages/security.yaml` grants the widget prefix to ROLE_API_SITE_REVIEW,
 * and the old Board path in its own line rather than folding it under the
 * ^/api/live-review prefix, so a reader of that file sees that a widget token
 * may write to the board.
 */
#[OA\Post(operationId: 'createWidgetCard', summary: 'Create a card from the widget', requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', additionalProperties: true)), tags: ['Widget'], parameters: [
    new OA\Parameter(name: 'X-Loupe-Project', description: 'The project id this request acts on, for a credential that covers several projects. A credential bound to one project may only confirm it.', in: 'header', schema: new OA\Schema(type: 'string', format: 'uuid')),
], responses: [
    new OA\Response(response: 201, description: 'The new card.', content: new OA\JsonContent(type: 'object', additionalProperties: true)),
    new OA\Response(response: 400, description: 'Refused: `invalid_json`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    new OA\Response(response: 401, description: 'Refused: `invalid_credentials`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    new OA\Response(response: 402, description: 'Refused: `subscription_required`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    new OA\Response(response: 403, description: 'Refused: `token_not_bound_to_site`, `credential_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    new OA\Response(response: 404, description: 'Refused: `route_not_found`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    new OA\Response(response: 405, description: 'Refused: `method_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    new OA\Response(response: 415, description: 'Refused: `unsupported_media_type`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    new OA\Response(response: 422, description: 'Refused: `unknown_type`, `validation_failed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    new OA\Response(response: 429, description: 'Refused: `rate_limited`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
])]
#[Route(
    '/api/v1/live-review-widget/cards',
    name: 'api_board_card_create',
    methods: ['POST'],
)]
#[Route(
    '/api/board/cards',
    name: 'api_legacy_board_card_create',
    methods: ['POST'],
)]
final class CreateCardController extends AppController
{
    public function __construct(
        private readonly CreateCardHandler $handler,
        private readonly AuthenticatedProjectResolver $projectResolver,
        private readonly UrlGeneratorInterface $urls,
        private readonly CardTypeCatalog $catalog,
    ) {
    }

    public function __invoke(#[MapRequestPayload] CreateCardRequest $payload): JsonResponse
    {
        $project = $this->projectResolver->resolveWidgetProject();
        if (null === $project) {
            return $this->json(['error' => 'token_not_bound_to_site'], JsonResponse::HTTP_FORBIDDEN);
        }

        $title = trim($payload->title ?? '');
        if ('' === $title) {
            throw new \LogicException('title required after validation');
        }

        $types = $this->catalog->forProject($project->requireId());
        $allowed = $payload->parent ? $types->withChildren() : $types->keys();
        $type = $payload->type ?? ($payload->parent ? ($allowed[0] ?? null) : $types->defaultKey);
        if (null === $type || !\in_array($type, $allowed, true)) {
            return $this->json(['error' => 'unknown_type', 'types' => $allowed], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $card = ($this->handler)(new CreateCardCommand(
            project: $project,
            title: $title,
            body: $payload->body,
            type: $type,
            // Not Human: nobody authenticated the person who typed this.
            reporter: Actor::Reviewer,
            source: new CardSource(CardSourceKind::Widget),
        ));

        return $this->json([
            'cardId' => (string) $card->id,
            'number' => $card->number,
            // The same pair the widget already renders for a resolved marker,
            // so it can show the new card with no second request.
            'label' => \sprintf('#%d %s', $card->number, $card->title),
            'url' => $this->urls->generate(
                'app_board_card',
                ['projectId' => (string) $project->id, 'cardId' => (string) $card->id],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
        ], JsonResponse::HTTP_CREATED);
    }
}
