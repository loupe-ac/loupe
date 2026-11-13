<?php

declare(strict_types=1);

namespace App\Module\Workflow\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicGet;
use App\Api\OpenApi\SuccessResponse;
use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Security\ReachableProject;
use App\Module\Workflow\Command\ShowWorkflowCommand;
use App\Module\Workflow\Command\ShowWorkflowHandler;
use App\Module\Workflow\Template\TemplateMissing;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[PublicGet(
    operationId: 'getWorkflow',
    summary: 'Read the workflow of a project',
    tags: ['Workflow'],
    description: 'The template and its version, the settings the template declares with their resolved values, the board columns with the workflow slot that links to each, and each kind of work the workflow can ask a bridge for, with the rules that ask for it and the checks it needs from the project. A project that has no workflow answers 409 `no_workflow`.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
    ],
    responses: [
        new SuccessResponse(200, 'The workflow of the project.', WorkflowResponse::class),
        new ProblemResponse(404, ['project_not_found', 'route_not_found']),
        new ProblemResponse(409, ['no_workflow']),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/workflow',
    name: 'api_workflow_show',
    methods: ['GET'],
)]
final class ShowWorkflowController extends AppController
{
    public function __construct(
        private readonly ShowWorkflowHandler $showWorkflow,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(#[ReachableProject] Project $project): JsonResponse
    {
        try {
            $view = ($this->showWorkflow)(new ShowWorkflowCommand($project));
        } catch (TemplateMissing) {
            throw ApiProblemException::refused(409, 'no_workflow', 'This project has no workflow. The owner chooses a workflow template for the project.');
        }

        return new JsonResponse(WorkflowResponse::fromView($view, $this->translator));
    }
}
