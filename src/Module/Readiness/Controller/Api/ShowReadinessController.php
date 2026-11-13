<?php

declare(strict_types=1);

namespace App\Module\Readiness\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicGet;
use App\Api\OpenApi\SuccessResponse;
use App\Controller\AppController;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Security\ReachableProject;
use App\Module\Readiness\Command\ShowReadinessCommand;
use App\Module\Readiness\Command\ShowReadinessHandler;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[PublicGet(
    operationId: 'getReadiness',
    summary: 'Read the readiness checks of a project',
    tags: ['Readiness'],
    description: 'Each check says whether it is done, with a short status. `discovery` is the latest discovery run, or null when discovery never ran. To start discovery, call `POST .../discovery`.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
    ],
    responses: [
        new SuccessResponse(200, 'The readiness checks of the project.', ReadinessResponse::class),
        new ProblemResponse(404, ['project_not_found', 'route_not_found']),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/readiness',
    name: 'api_readiness_show',
    methods: ['GET'],
)]
final class ShowReadinessController extends AppController
{
    public function __construct(
        private readonly ShowReadinessHandler $showReadiness,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(#[ReachableProject] Project $project): JsonResponse
    {
        return new JsonResponse(ReadinessResponse::fromView(($this->showReadiness)(new ShowReadinessCommand($project)), $this->translator));
    }
}
