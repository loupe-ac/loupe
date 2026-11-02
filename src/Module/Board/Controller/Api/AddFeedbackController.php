<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Board\Command\AddFeedbackCommand;
use App\Module\Board\Command\AddFeedbackHandler;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\SiteReview\Command\NewStroke;
use App\Module\SiteReview\Entity\ReviewSurface;
use App\Module\SiteReview\SiteReviewDrawing;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * Saves a widget note on its card, and creates the card when the note asks
 * for one. `config/packages/security.yaml` grants the path to the widget token
 * in its own line, next to the card picker's.
 */
#[OA\Post(operationId: 'addWidgetFeedback', summary: 'Save a widget note on its card', requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', additionalProperties: true)), tags: ['Widget'], parameters: [
    new OA\Parameter(name: 'X-Loupe-Project', description: 'The project id this request acts on, for a credential that covers several projects. A credential bound to one project may only confirm it.', in: 'header', schema: new OA\Schema(type: 'string', format: 'uuid')),
], responses: [
    new OA\Response(response: 201, description: 'The note is saved.', content: new OA\JsonContent(ref: '#/components/schemas/FeedbackAdded')),
    new OA\Response(response: 400, description: 'Refused: `invalid_json`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    new OA\Response(response: 401, description: 'Refused: `invalid_credentials`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    new OA\Response(response: 402, description: 'Refused: `subscription_required`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    new OA\Response(response: 403, description: 'Refused: `token_not_bound_to_site`, `credential_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    new OA\Response(response: 404, description: 'Refused: `route_not_found`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    new OA\Response(response: 405, description: 'Refused: `method_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    new OA\Response(response: 409, description: 'Refused: `delivery_conflict`, `delivery_invalid`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    new OA\Response(response: 415, description: 'Refused: `unsupported_media_type`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    new OA\Response(response: 422, description: 'Refused: `drawing_disabled`, `anchor_stroke_without_anchor`, `target_not_found`, `target_closed`, `target_not_epic`, `screenshot_invalid`, `screenshot_too_large`, `feedback_refused`, `validation_failed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    new OA\Response(response: 429, description: 'Refused: `rate_limited`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
])]
#[Route(
    '/api/v1/live-review-widget/feedback',
    name: 'api_board_feedback_add',
    methods: ['POST'],
)]
#[Route(
    '/api/board/feedback',
    name: 'api_legacy_board_feedback_add',
    methods: ['POST'],
)]
final class AddFeedbackController extends AppController
{
    public function __construct(
        private readonly AddFeedbackHandler $handler,
        private readonly AuthenticatedProjectResolver $projectResolver,
        private readonly FeatureFlagService $featureFlags,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function __invoke(#[MapRequestPayload] AddFeedbackRequest $payload): JsonResponse
    {
        $project = $this->projectResolver->resolveWidgetProject();
        if (null === $project) {
            throw ApiProblemException::refused(JsonResponse::HTTP_FORBIDDEN, 'token_not_bound_to_site', 'The token is not bound to a site.');
        }

        if ([] !== $payload->strokes && !$this->featureFlags->isEnabled(SiteReviewDrawing::FLAG, SiteReviewDrawing::DEFAULT)) {
            throw ApiProblemException::refused(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'drawing_disabled', 'Drawing is switched off for this project.');
        }

        $body = trim($payload->body ?? '');
        $target = $payload->target;
        if ('' === $body || null === $target) {
            throw new \LogicException('body and target required after validation');
        }

        $anchors = $payload->newAnchors();
        $strokes = $payload->newStrokes();
        // An anchor-space stroke measures against anchor 0, so with no anchor
        // it can never be drawn again.
        $anchorSpace = array_filter($strokes, static fn (NewStroke $stroke): bool => 'anchor' === $stroke->space);
        if ([] === $anchors && [] !== $anchorSpace) {
            throw ApiProblemException::refused(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'anchor_stroke_without_anchor', 'A stroke needs an anchor.');
        }

        try {
            $link = ($this->handler)(new AddFeedbackCommand(
                project: $project,
                body: $body,
                url: ReviewSurface::Web === $payload->surface ? trim($payload->url ?? '') : null,
                anchors: $anchors,
                strokes: $strokes,
                deliveryId: $payload->deliveryId,
                cardId: $target->cardId,
                parentCardId: $target->newCard?->parentCardId,
                context: $payload->context(),
                surface: $payload->surface,
                native: $payload->native?->facts(),
                screenshot: $payload->newScreenshot(),
            ));
        } catch (DomainErrors $error) {
            $field = array_key_first($error->errors);

            // A sub-handler refusal, such as a card write losing a race, carries
            // a translation key the widget cannot name, so it gets a stable one.
            throw match ($field) {
                'target', 'screenshot' => ApiProblemException::refused(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, $error->errors[$field], 'The feedback was refused.'),
                'deliveryId' => ApiProblemException::refused(JsonResponse::HTTP_CONFLICT, $error->errors[$field], 'The feedback was refused.'),
                default => ApiProblemException::refused(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'feedback_refused', 'The feedback was refused.'),
            };
        }

        $card = $link->card;

        return $this->json([
            'commentId' => (string) $link->comment->id,
            'cardId' => (string) $card->id,
            'number' => $card->number,
            'label' => \sprintf('#%d %s', $card->number, $card->title),
            'url' => $this->urls->generate(
                'app_board_card',
                ['projectId' => (string) $project->id, 'cardId' => (string) $card->id],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
        ], JsonResponse::HTTP_CREATED);
    }
}
