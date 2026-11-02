<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller\Api;

use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\SiteReview\Command\CommentNotFound;
use App\Module\SiteReview\Command\ResolveCommentCommand;
use App\Module\SiteReview\Command\ResolveCommentHandler;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route(
    '/api/live-review/comments/{id}/resolve',
    name: 'api_site_review_comment_resolve',
    methods: ['POST'],
)]
final class ResolveCommentController extends AppController
{
    public function __construct(
        private readonly ResolveCommentHandler $handler,
        private readonly AuthenticatedProjectResolver $projectResolver,
    ) {
    }

    public function __invoke(string $id): JsonResponse
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

        try {
            ($this->handler)(new ResolveCommentCommand(
                project: $project,
                commentId: $commentId,
            ));
        } catch (CommentNotFound) {
            throw ApiProblemException::refused(JsonResponse::HTTP_NOT_FOUND, 'not_found', 'No such record exists for this token.');
        }

        return new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
    }
}
