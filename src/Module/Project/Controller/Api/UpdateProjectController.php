<?php

declare(strict_types=1);

namespace App\Module\Project\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicPatch;
use App\Api\OpenApi\SuccessResponse;
use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Project\Command\UpdateProjectCommand;
use App\Module\Project\Command\UpdateProjectHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\ProjectEventType;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Security\ReachableProject;
use App\Module\Project\Service\ProjectApiProblems;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[PublicPatch(
    operationId: 'updateProject',
    summary: 'Change a project',
    tags: ['Projects'],
    description: 'A new name changes the slug, so a reference to the old slug, such as a bridge rule file, stops matching.',
    parameters: [new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project')],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ProjectUpdate')),
    responses: [
        new SuccessResponse(200, 'The project after the change.', ProjectResponse::class),
        new ProblemResponse(400, ['invalid_json']),
        new ProblemResponse(404, ['project_not_found', 'route_not_found']),
        new ProblemResponse(409, ['name_taken', 'slug_taken'], 'NameTakenProblem'),
        new ProblemResponse(415, ['unsupported_media_type']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}',
    name: 'api_project_update',
    methods: ['PATCH'],
)]
final class UpdateProjectController extends AppController
{
    public function __construct(
        private readonly UpdateProjectHandler $updateProject,
        private readonly ProjectApiProblems $problems,
    ) {
    }

    public function __invoke(#[ReachableProject] Project $project, #[MapRequestPayload] UpdateProjectRequest $payload): JsonResponse
    {
        try {
            $saved = ($this->updateProject)(new UpdateProjectCommand(
                project: $project,
                name: $payload->name ?? null,
                domain: $payload->domain ?? null,
                searchLanguage: $payload->searchLanguage ?? null,
                actor: ProjectEventType::ACTOR_HUMAN,
                description: $payload->description ?? null,
                origins: $payload->origins ?? null,
            ));
        } catch (DomainErrors $e) {
            throw $this->problems->forUpdate($e, $project->owner, $payload->name ?? null);
        }

        return new JsonResponse(ProjectResponse::fromProject($saved));
    }
}
