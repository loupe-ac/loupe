<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicGet;
use App\Api\OpenApi\SuccessResponse;
use App\Api\Pagination\Cursor;
use App\Api\Pagination\Page;
use App\Api\Pagination\PageRequest;
use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use App\Module\Bridge\Command\ListExperimentsCommand;
use App\Module\Bridge\Command\ListExperimentsHandler;
use App\Module\Bridge\Experiment\ExperimentListPosition;
use App\Module\Bridge\Experiment\ExperimentSummary;
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
    operationId: 'listExperiments',
    summary: 'List the experiments',
    tags: ['Experiments'],
    description: 'The experiments that the runs and the pins of the project name, the latest run first and the experiments with no run last. Each row is the name of an experiment, so a script can find the name that the other experiment routes take. The state of the project can change between two pages of a walk, so an experiment can repeat or be missed.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'limit', description: 'The most rows to return.', in: 'query', schema: new OA\Schema(type: 'integer', default: PageRequest::DEFAULT_LIMIT, maximum: PageRequest::MAX_LIMIT, minimum: 1)),
        new OA\Parameter(name: 'cursor', description: 'The opaque position from the `next` URL of the last page.', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'archived', description: 'True lists only the archived experiments. The default lists only the others.', in: 'query', schema: new OA\Schema(type: 'boolean', default: false)),
    ],
    responses: [
        new SuccessResponse(200, 'One page of experiments.', ExperimentSummaryListResponse::class, page: true),
        new ProblemResponse(400, ['invalid_cursor']),
        new ProblemResponse(404, ['project_not_found', 'route_not_found']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/experiments',
    name: 'api_experiment_list',
    methods: ['GET'],
)]
final class ListExperimentsController extends AppController
{
    private const string SORT = 'lastRunName';

    public function __construct(
        private readonly ListExperimentsHandler $listExperiments,
        private readonly ExperimentReportPayload $payload,
    ) {
    }

    public function __invoke(Request $request, #[ReachableProject] Project $project): JsonResponse
    {
        $archived = match ($request->query->get('archived')) {
            null, 'false', '0' => false,
            'true', '1' => true,
            default => throw ApiProblemException::validationFailed([['field' => 'archived', 'code' => 'invalid_value', 'message' => 'archived must be true or false.']]),
        };

        $filters = ['archived' => $archived];
        $page = PageRequest::fromRequest($request, self::SORT, $filters);
        $after = null;
        if (null !== $page->cursor) {
            [$lastRunAt, $name] = $page->cursor->lastRunNamePosition();
            $after = new ExperimentListPosition($lastRunAt, $name);
        }

        $view = ($this->listExperiments)(new ListExperimentsCommand(project: $project, archived: $archived, after: $after, limit: $page->limit));

        $next = null;
        $last = [] === $view->experiments ? null : array_last($view->experiments);
        if ($view->hasMore && null !== $last) {
            $next = Cursor::afterLastRunName(self::SORT, $last->lastRunAt, $last->name, $filters);
        }

        return new Page($view->experiments, $next)->toResponse($request, fn (ExperimentSummary $summary): array => $this->payload->forSummary($summary));
    }
}
