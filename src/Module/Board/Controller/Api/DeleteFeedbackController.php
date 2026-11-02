<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Controller\AppController;
use App\Module\Board\Command\DeleteFeedbackCommand;
use App\Module\Board\Command\DeleteFeedbackHandler;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\SiteReview\Command\CommentNotFound;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Deletes a pending widget note, and the card it created when that card is
 * still untouched. The answer says whether the card went, so the widget can
 * drop it from its picker.
 */
#[OA\Delete(
    operationId: 'deleteWidgetFeedback',
    summary: 'Delete a pending widget note',
    tags: ['Widget'],
    parameters: [
        new OA\Parameter(name: 'X-Loupe-Project', description: 'The project id this request acts on, for a credential that covers several projects. A credential bound to one project may only confirm it.', in: 'header', schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'id', description: 'The note id. A value that is not a uuid answers 404 `not_found`.', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'The note went.', content: new OA\JsonContent(type: 'object', additionalProperties: true)),
        new OA\Response(response: 401, description: 'Refused: `invalid_credentials`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 402, description: 'Refused: `subscription_required`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 403, description: 'Refused: `token_not_bound_to_site`, `credential_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 404, description: 'Refused: `not_found`, `route_not_found`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 405, description: 'Refused: `method_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 429, description: 'Refused: `rate_limited`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    ],
)]
#[Route(
    '/api/v1/live-review-widget/feedback/{id}',
    name: 'api_board_feedback_delete',
    methods: ['DELETE'],
)]
#[Route(
    '/api/board/feedback/{id}',
    name: 'api_legacy_board_feedback_delete',
    methods: ['DELETE'],
)]
final class DeleteFeedbackController extends AppController
{
    public function __construct(
        private readonly DeleteFeedbackHandler $handler,
        private readonly AuthenticatedProjectResolver $projectResolver,
    ) {
    }

    public function __invoke(string $id): JsonResponse
    {
        $project = $this->projectResolver->resolveWidgetProject();
        if (null === $project) {
            return $this->json(['error' => 'token_not_bound_to_site'], JsonResponse::HTTP_FORBIDDEN);
        }

        try {
            $commentId = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            return $this->json(['error' => 'not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        try {
            $cardDeleted = ($this->handler)(new DeleteFeedbackCommand($project, $commentId));
        } catch (CommentNotFound) {
            return $this->json(['error' => 'not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        return $this->json(['cardDeleted' => $cardDeleted]);
    }
}
