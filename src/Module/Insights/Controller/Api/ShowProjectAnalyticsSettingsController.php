<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicGet;
use App\Api\OpenApi\SuccessResponse;
use App\Controller\AppController;
use App\Module\Insights\Command\ShowAnalyticsSettingsCommand;
use App\Module\Insights\Command\ShowAnalyticsSettingsHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Security\ReachableProject;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[PublicGet(
    operationId: 'getAnalyticsSettings',
    summary: 'Read the analytics settings of a project',
    tags: ['Analytics settings'],
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
    ],
    responses: [
        new SuccessResponse(200, 'The analytics settings.', AnalyticsSettingsResponse::class),
        new ProblemResponse(404, ['project_not_found', 'route_not_found']),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/analytics/settings',
    name: 'api_analytics_settings_show',
    methods: ['GET'],
)]
final class ShowProjectAnalyticsSettingsController extends AppController
{
    public function __construct(
        private readonly ShowAnalyticsSettingsHandler $showSettings,
    ) {
    }

    public function __invoke(#[ReachableProject] Project $project): JsonResponse
    {
        return new JsonResponse(AnalyticsSettingsResponse::fromView(($this->showSettings)(new ShowAnalyticsSettingsCommand($project))));
    }
}
