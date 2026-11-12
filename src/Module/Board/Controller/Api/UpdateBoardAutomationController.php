<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicPatch;
use App\Api\OpenApi\SuccessResponse;
use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Board\Command\SaveBoardAutomationSettingsCommand;
use App\Module\Board\Command\SaveBoardAutomationSettingsHandler;
use App\Module\Board\Command\ShowBoardAutomationCommand;
use App\Module\Board\Command\ShowBoardAutomationHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Security\ReachableProject;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[PublicPatch(
    operationId: 'updateBoardAutomation',
    summary: 'Change the automation settings of a project',
    tags: ['Workflow'],
    description: 'A setting you leave out keeps its value. The three changes save together, so a refusal changes none of them. Turning `enabled` off stops the workflow from moving cards and from asking for work.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
    ],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: UpdateBoardAutomationRequest::class))),
    responses: [
        new SuccessResponse(200, 'The settings after the change.', BoardAutomationResponse::class),
        new ProblemResponse(400, ['invalid_json']),
        new ProblemResponse(404, ['project_not_found', 'route_not_found']),
        new ProblemResponse(409, ['no_workflow']),
        new ProblemResponse(415, ['unsupported_media_type']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/workflow/automation',
    name: 'api_workflow_automation_update',
    methods: ['PATCH'],
)]
final class UpdateBoardAutomationController extends AppController
{
    public function __construct(
        private readonly SaveBoardAutomationSettingsHandler $saveSettings,
        private readonly ShowBoardAutomationHandler $showAutomation,
    ) {
    }

    public function __invoke(#[ReachableProject] Project $project, #[MapRequestPayload] UpdateBoardAutomationRequest $payload): JsonResponse
    {
        $current = ($this->showAutomation)(new ShowBoardAutomationCommand($project));

        try {
            ($this->saveSettings)(new SaveBoardAutomationSettingsCommand(
                project: $project,
                enabled: $payload->enabled ?? $current->enabled,
                stuckDelayMinutes: $payload->stuckDelayMinutes ?? $current->stuckDelayMinutes,
                approvalCoversConflictResolution: $payload->approvalCoversConflictResolution ?? null,
            ));
        } catch (DomainErrors $e) {
            $key = array_first($e->errors);
            throw match ($key) {
                SaveBoardAutomationSettingsHandler::NO_WORKFLOW => ApiProblemException::refused(409, 'no_workflow', 'This project has no workflow, so it has no approval rule to change. The owner chooses a workflow template for the project.'),
                SaveBoardAutomationSettingsHandler::STUCK_DELAY_INVALID => ApiProblemException::validationFailed([['field' => 'stuckDelayMinutes', 'code' => 'stuck_delay_invalid', 'message' => 'The stuck delay is from 1 to 1440 minutes.']]),
                default => $e,
            };
        }

        return new JsonResponse(BoardAutomationResponse::fromView(($this->showAutomation)(new ShowBoardAutomationCommand($project))));
    }
}
