<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicGet;
use App\Api\OpenApi\SuccessResponse;
use App\Api\PageLinks;
use App\Api\Pagination\CreatedSort;
use App\Api\Pagination\PageRequest;
use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Board\Command\PageFeedbackCommand;
use App\Module\Board\Command\PageFeedbackHandler;
use App\Module\Board\Service\FeedbackApiProblems;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Security\ReachableProject;
use App\Module\SiteReview\Entity\SiteReviewComment;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[PublicGet(
    operationId: 'listFeedback',
    summary: 'List the feedback of the project',
    tags: ['Feedback'],
    description: '`screenshot` gives the size of the marked image. The image itself is not in this answer.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'limit', description: 'The most rows to return.', in: 'query', schema: new OA\Schema(type: 'integer', default: PageRequest::DEFAULT_LIMIT, maximum: PageRequest::MAX_LIMIT, minimum: 1)),
        new OA\Parameter(name: 'cursor', description: 'The opaque position from the `next` URL of the last page.', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'sort', description: 'The sort order. The default is creation order, oldest first. A leading `-` sorts newest first.', in: 'query', schema: new OA\Schema(type: 'string', default: 'createdAt', enum: ['createdAt', '-createdAt'])),
        new OA\Parameter(name: 'status', description: 'Which feedback to return.', in: 'query', schema: new OA\Schema(type: 'string', default: FeedbackFilter::DEFAULT_STATUS, enum: ['pending', 'addressed', 'resolved', 'all'])),
        new OA\Parameter(name: 'cardId', description: 'Only the feedback of this card.', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new SuccessResponse(200, 'One page of feedback.', FeedbackListResponse::class, page: true),
        new ProblemResponse(400, ['invalid_cursor']),
        new ProblemResponse(404, ['project_not_found', 'card_not_found', 'route_not_found']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/feedback',
    name: 'api_feedback_list',
    methods: ['GET'],
)]
final class ListFeedbackController extends AppController
{
    public function __construct(
        private readonly PageFeedbackHandler $pageFeedback,
        private readonly FeedbackApiProblems $problems,
        private readonly PageLinks $links,
    ) {
    }

    public function __invoke(Request $request, #[ReachableProject] Project $project): JsonResponse
    {
        $filter = FeedbackFilter::fromRequest($request);
        $sort = CreatedSort::fromRequest($request);

        try {
            $view = ($this->pageFeedback)(new PageFeedbackCommand(
                project: $project,
                status: $filter->status,
                cardId: $filter->cardId,
                page: PageRequest::fromRequest($request, $sort->value, $filter->filters()),
                sort: $sort,
                filters: $filter->filters(),
            ));
        } catch (DomainErrors $e) {
            throw $this->problems->from($e);
        }

        return $view->page->toResponse(
            $request,
            fn (SiteReviewComment $comment): FeedbackResponse => FeedbackResponse::of($comment, $view->links[(string) $comment->id] ?? null, $view->sizes[(string) $comment->id] ?? null, $this->links),
        );
    }
}
