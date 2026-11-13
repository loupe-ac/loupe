<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicPatch;
use App\Api\OpenApi\SuccessResponse;
use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Insights\Command\ShowAnalyticsSettingsCommand;
use App\Module\Insights\Command\ShowAnalyticsSettingsHandler;
use App\Module\Insights\Command\UpdateAnalyticsSettingsCommand;
use App\Module\Insights\Command\UpdateAnalyticsSettingsHandler;
use App\Module\Insights\Service\InsightsApiProblems;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Security\ReachableProject;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[PublicPatch(
    operationId: 'updateAnalyticsSettings',
    summary: 'Change the analytics settings of a project',
    tags: ['Analytics settings'],
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
    ],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: UpdateAnalyticsSettingsRequest::class))),
    responses: [
        new SuccessResponse(200, 'The settings after the change.', AnalyticsSettingsResponse::class),
        new ProblemResponse(400, ['invalid_json']),
        new ProblemResponse(404, ['project_not_found', 'route_not_found']),
        new ProblemResponse(415, ['unsupported_media_type']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/analytics/settings',
    name: 'api_analytics_settings_update',
    methods: ['PATCH'],
)]
final class UpdateProjectAnalyticsSettingsController extends AppController
{
    public function __construct(
        private readonly UpdateAnalyticsSettingsHandler $updateSettings,
        private readonly ShowAnalyticsSettingsHandler $showSettings,
        private readonly InsightsApiProblems $problems,
    ) {
    }

    public function __invoke(Request $request, #[ReachableProject] Project $project, #[MapRequestPayload] UpdateAnalyticsSettingsRequest $payload): JsonResponse
    {
        $named = $request->getPayload();
        $programs = $named->has('subcommandPrograms') ? array_values($payload->subcommandPrograms) : null;

        try {
            ($this->updateSettings)(new UpdateAnalyticsSettingsCommand(
                project: $project,
                model: $payload->defaultModel ?? null,
                effort: $payload->defaultEffort ?? null,
                collectFullText: $named->has('collectFullText') && $payload->collectFullText,
                changeModel: $named->has('defaultModel'),
                changeEffort: $named->has('defaultEffort'),
                changeCollectFullText: $named->has('collectFullText'),
                subcommandPrograms: [] === $programs ? null : $programs,
                changeSubcommandPrograms: $named->has('subcommandPrograms'),
            ));
        } catch (DomainErrors $e) {
            throw $this->problems->forSettings($e);
        }

        return new JsonResponse(AnalyticsSettingsResponse::fromView(($this->showSettings)(new ShowAnalyticsSettingsCommand($project))));
    }
}
