<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicGet;
use App\Api\OpenApi\SuccessResponse;
use App\Controller\AppController;
use App\Module\Board\Command\ShowBoardAutomationCommand;
use App\Module\Board\Command\ShowBoardAutomationHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Security\ReachableProject;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[PublicGet(
    operationId: 'getBoardAutomation',
    summary: 'Read the automation settings of a project',
    tags: ['Workflow'],
    description: 'The same switches as the Automation page of the project settings.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
    ],
    responses: [
        new SuccessResponse(200, 'The automation settings.', BoardAutomationResponse::class),
        new ProblemResponse(404, ['project_not_found', 'route_not_found']),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/workflow/automation',
    name: 'api_workflow_automation_show',
    methods: ['GET'],
)]
final class ShowBoardAutomationController extends AppController
{
    public function __construct(
        private readonly ShowBoardAutomationHandler $showAutomation,
    ) {
    }

    public function __invoke(#[ReachableProject] Project $project): JsonResponse
    {
        return new JsonResponse(BoardAutomationResponse::fromView(($this->showAutomation)(new ShowBoardAutomationCommand($project))));
    }
}
