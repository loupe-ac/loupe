<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use App\Module\Board\Command\DeleteFeedbackCommand;
use App\Module\Board\Command\DeleteFeedbackHandler;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\SiteReview\Command\CommentNotFound;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Deletes a pending widget note, and the card it created when that card is
 * still untouched. The answer says whether the card went, so the widget can
 * drop it from its picker.
 */
#[Route(
    '/api/board/feedback/{id}',
    name: 'api_board_feedback_delete',
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
            throw ApiProblemException::refused(JsonResponse::HTTP_FORBIDDEN, 'token_not_bound_to_site', 'The token is not bound to a site.');
        }

        try {
            $commentId = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw ApiProblemException::refused(JsonResponse::HTTP_NOT_FOUND, 'not_found', 'No such record exists for this token.');
        }

        try {
            $cardDeleted = ($this->handler)(new DeleteFeedbackCommand($project, $commentId));
        } catch (CommentNotFound) {
            throw ApiProblemException::refused(JsonResponse::HTTP_NOT_FOUND, 'not_found', 'No such record exists for this token.');
        }

        return $this->json(['cardDeleted' => $cardDeleted]);
    }
}
