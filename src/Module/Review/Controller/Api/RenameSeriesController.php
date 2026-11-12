<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicPatch;
use App\Api\OpenApi\SuccessResponse;
use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Security\ReachableProject;
use App\Module\Review\Command\RenameSeriesCommand;
use App\Module\Review\Command\RenameSeriesHandler;
use App\Module\Review\Command\ShowSeriesCommand;
use App\Module\Review\Command\ShowSeriesHandler;
use App\Module\Review\Entity\Series;
use App\Module\Review\Security\ProjectSeries;
use App\Module\Review\Service\DocumentApiProblems;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[PublicPatch(
    operationId: 'renameSeries',
    summary: 'Rename a series',
    tags: ['Series'],
    description: 'Every document of the series keeps its position. The name must not belong to another series, ignoring case, because two series carry two independent numberings. Renaming a series to its own name in another case re-spells it.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'series', description: 'The current name of the series, URL-encoded, matched ignoring case.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'rust-atomics'),
    ],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: RenameSeriesRequest::class))),
    responses: [
        new SuccessResponse(200, 'The series under its new name.', SeriesRowResponse::class),
        new ProblemResponse(400, ['invalid_json']),
        new ProblemResponse(404, ['project_not_found', 'series_not_found', 'route_not_found']),
        new ProblemResponse(409, ['series_name_taken']),
        new ProblemResponse(415, ['unsupported_media_type']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/series/{series}',
    name: 'api_series_rename',
    requirements: ['series' => '.+'],
    methods: ['PATCH'],
)]
final class RenameSeriesController extends AppController
{
    public function __construct(
        private readonly RenameSeriesHandler $renameSeries,
        private readonly ShowSeriesHandler $showSeries,
        private readonly DocumentApiProblems $problems,
    ) {
    }

    public function __invoke(#[ReachableProject] Project $project, #[ProjectSeries] Series $series, #[MapRequestPayload] RenameSeriesRequest $body): JsonResponse
    {
        try {
            $outcome = ($this->renameSeries)(new RenameSeriesCommand($series, $body->name ?? throw new \LogicException('name required after validation')));
        } catch (DomainErrors $e) {
            throw $this->problems->from($e);
        }

        $row = ($this->showSeries)(new ShowSeriesCommand($outcome->series));

        return new JsonResponse(['name' => $row['series']->name, 'documentCount' => $row['documentCount'], 'highestOrdinal' => $row['highestOrdinal']]);
    }
}
