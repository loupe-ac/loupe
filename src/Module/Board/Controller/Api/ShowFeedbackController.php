<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Api\Fields\FieldSelection;
use App\Api\OpenApi\FieldsParameter;
use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicGet;
use App\Api\OpenApi\SuccessResponse;
use App\Controller\AppController;
use App\Module\Board\Command\ShowFeedbackCommand;
use App\Module\Board\Command\ShowFeedbackHandler;
use App\Module\Board\Service\FeedbackApiProblems;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Security\ReachableProject;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[PublicGet(
    operationId: 'getFeedback',
    summary: 'Read one feedback item',
    tags: ['Feedback'],
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'feedback', description: 'The feedback id.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new FieldsParameter(FeedbackResponse::FIELDS),
    ],
    responses: [
        new SuccessResponse(200, 'The item.', FeedbackResponse::class),
        new ProblemResponse(404, ['project_not_found', 'feedback_not_found', 'route_not_found']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/feedback/{feedback}',
    name: 'api_feedback_show',
    methods: ['GET'],
)]
final class ShowFeedbackController extends AppController
{
    public function __construct(
        private readonly ShowFeedbackHandler $showFeedback,
        private readonly FeedbackApiProblems $problems,
    ) {
    }

    public function __invoke(Request $request, #[ReachableProject] Project $project, string $feedback): JsonResponse
    {
        $fields = FieldSelection::fromRequest($request, FeedbackResponse::FIELDS);
        $view = ($this->showFeedback)(new ShowFeedbackCommand($project, $feedback)) ?? throw $this->problems->feedbackNotFound();

        return new JsonResponse($fields->keepObject(FeedbackResponse::fromView($view)));
    }
}
