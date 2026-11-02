<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller\Api;

use App\Api\OpenApi\RateLimitedResponse;
use App\Controller\AppController;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\SiteReview\Command\CommentNotFound;
use App\Module\SiteReview\Command\UpdateCommentCommand;
use App\Module\SiteReview\Command\UpdateCommentHandler;
use App\Module\SiteReview\Context\CommentLabelResolverInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[OA\Patch(operationId: 'updateWidgetComment', summary: 'Change the text of a widget note', requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', additionalProperties: true)), tags: ['Widget'], parameters: [
    new OA\Parameter(name: 'X-Loupe-Project', description: 'The project id this request acts on, for a credential that covers several projects. A credential bound to one project may only confirm it.', in: 'header', schema: new OA\Schema(type: 'string', format: 'uuid')),
    new OA\Parameter(name: 'id', description: 'The note id. A value that is not a uuid answers 404 `not_found`.', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
], responses: [
    new OA\Response(response: 200, description: 'The note after the change.', content: new OA\JsonContent(type: 'object', additionalProperties: true)),
    new OA\Response(response: 400, description: 'Refused: `invalid_json`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    new OA\Response(response: 401, description: 'Refused: `invalid_credentials`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    new OA\Response(response: 402, description: 'Refused: `subscription_required`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    new OA\Response(response: 403, description: 'Refused: `token_not_bound_to_site`, `credential_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    new OA\Response(response: 404, description: 'Refused: `not_found`, `route_not_found`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    new OA\Response(response: 405, description: 'Refused: `method_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    new OA\Response(response: 415, description: 'Refused: `unsupported_media_type`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    new OA\Response(response: 422, description: 'Refused: `validation_failed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    new RateLimitedResponse(),
])]
#[Route(
    '/api/v1/live-review-widget/comments/{id}',
    name: 'api_site_review_comment_update',
    methods: ['PATCH'],
)]
#[Route(
    '/api/live-review/comments/{id}',
    name: 'api_legacy_site_review_comment_update',
    methods: ['PATCH'],
)]
final class UpdateCommentController extends AppController
{
    public function __construct(
        private readonly UpdateCommentHandler $handler,
        private readonly AuthenticatedProjectResolver $projectResolver,
        private readonly CommentLabelResolverInterface $labels,
    ) {
    }

    public function __invoke(string $id, #[MapRequestPayload] UpdateCommentRequest $payload): JsonResponse
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

        $body = trim($payload->body ?? '');
        if ('' === $body) {
            throw new \LogicException('body required after validation');
        }

        try {
            $comment = ($this->handler)(new UpdateCommentCommand(
                project: $project,
                commentId: $commentId,
                body: $body,
            ));
        } catch (CommentNotFound) {
            return $this->json(['error' => 'not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        // The widget names the card in its save notice, as it does for a new note.
        return $this->json([
            'commentId' => (string) $comment->id,
            'label' => $this->labels->resolve($commentId, $project),
        ]);
    }
}
