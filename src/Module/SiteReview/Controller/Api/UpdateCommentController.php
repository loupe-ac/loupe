<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller\Api;

use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\SiteReview\Command\CommentNotFound;
use App\Module\SiteReview\Command\UpdateCommentCommand;
use App\Module\SiteReview\Command\UpdateCommentHandler;
use App\Module\SiteReview\Context\CommentLabelResolverInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route(
    '/api/live-review/comments/{id}',
    name: 'api_site_review_comment_update',
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
            throw ApiProblemException::refused(JsonResponse::HTTP_FORBIDDEN, 'token_not_bound_to_site', 'The token is not bound to a site.');
        }

        try {
            $commentId = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw ApiProblemException::refused(JsonResponse::HTTP_NOT_FOUND, 'not_found', 'No such record exists for this token.');
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
            throw ApiProblemException::refused(JsonResponse::HTTP_NOT_FOUND, 'not_found', 'No such record exists for this token.');
        }

        // The widget names the card in its save notice, as it does for a new note.
        return $this->json([
            'commentId' => (string) $comment->id,
            'label' => $this->labels->resolve($commentId, $project),
        ]);
    }
}
