<?php

declare(strict_types=1);

namespace App\Module\Readiness\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicPatch;
use App\Api\OpenApi\SuccessResponse;
use App\Controller\AppController;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Security\ReachableProject;
use App\Module\Readiness\Command\UpdateReadinessSettingsCommand;
use App\Module\Readiness\Command\UpdateReadinessSettingsHandler;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[PublicPatch(
    operationId: 'updateReadiness',
    summary: 'Show or hide the readiness guide',
    tags: ['Readiness'],
    description: 'The same switch as the Agent readiness tab of the project settings. Hiding the guide again keeps the time of the first hide.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
    ],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: UpdateReadinessRequest::class))),
    responses: [
        new SuccessResponse(200, 'Whether the guide shows after the change.', ReadinessGuideResponse::class),
        new ProblemResponse(400, ['invalid_json']),
        new ProblemResponse(404, ['project_not_found', 'route_not_found']),
        new ProblemResponse(415, ['unsupported_media_type']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/readiness',
    name: 'api_readiness_update',
    methods: ['PATCH'],
)]
final class UpdateReadinessController extends AppController
{
    public function __construct(
        private readonly UpdateReadinessSettingsHandler $updateSettings,
    ) {
    }

    public function __invoke(#[ReachableProject] Project $project, #[MapRequestPayload] UpdateReadinessRequest $body): JsonResponse
    {
        ($this->updateSettings)(new UpdateReadinessSettingsCommand($project, $body->guideShown ?? throw new \LogicException('guideShown required after validation')));

        return new JsonResponse(new ReadinessGuideResponse(
            null === $project->readinessGuideHiddenAt,
            $project->readinessGuideHiddenAt?->format(\DATE_ATOM),
        ));
    }
}
