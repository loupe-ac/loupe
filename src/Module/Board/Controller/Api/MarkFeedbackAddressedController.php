<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicPatch;
use App\Api\OpenApi\SuccessResponse;
use App\Api\PageLinks;
use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Board\Command\MarkFeedbackAddressedCommand;
use App\Module\Board\Command\MarkFeedbackAddressedHandler;
use App\Module\Board\Service\FeedbackApiProblems;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Security\ReachableProject;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * An item that is already addressed answers 200 and changes nothing. An item that a
 * person resolved answers 409, because the route never replaces a person's decision.
 */
#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[PublicPatch(
    operationId: 'markFeedbackAddressed',
    summary: 'Mark a feedback item addressed',
    tags: ['Feedback'],
    description: 'An item that is already addressed answers 200, and nothing changes.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'feedback', description: 'The feedback id.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: FeedbackUpdateRequest::class))),
    responses: [
        new SuccessResponse(200, 'The item after the change.', FeedbackResponse::class),
        new ProblemResponse(400, ['invalid_json']),
        new ProblemResponse(404, ['project_not_found', 'feedback_not_found', 'route_not_found']),
        new ProblemResponse(409, ['feedback_already_resolved']),
        new ProblemResponse(415, ['unsupported_media_type']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/feedback/{feedback}',
    name: 'api_feedback_mark_addressed',
    methods: ['PATCH'],
)]
final class MarkFeedbackAddressedController extends AppController
{
    public function __construct(
        private readonly MarkFeedbackAddressedHandler $markAddressed,
        private readonly FeedbackApiProblems $problems,
        private readonly PageLinks $links,
    ) {
    }

    public function __invoke(#[ReachableProject] Project $project, string $feedback, #[MapRequestPayload] FeedbackUpdateRequest $payload): JsonResponse
    {
        try {
            $view = ($this->markAddressed)(new MarkFeedbackAddressedCommand($project, $feedback));
        } catch (DomainErrors $e) {
            throw $this->problems->from($e);
        }

        return new JsonResponse(FeedbackResponse::fromView($view, $this->links));
    }
}
