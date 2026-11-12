<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicGet;
use App\Api\OpenApi\SuccessResponse;
use App\Api\Pagination\Cursor;
use App\Api\Pagination\Page;
use App\Api\Pagination\PageRequest;
use App\Controller\AppController;
use App\Module\Bridge\Command\ListMetricsCommand;
use App\Module\Bridge\Command\ListMetricsHandler;
use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricGroup;
use App\Module\Bridge\Metric\MetricKey;
use App\Module\Bridge\Metric\MetricShape;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricUnit;
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
    operationId: 'listMetrics',
    summary: 'List the metrics',
    tags: ['Analytics'],
    description: 'The metrics that the metric query reads, by key, with the units, statistics and groups each one takes. The workflow template of the project can declare more metrics. One `bucket-time`, `bucket-time-all` and `bucket-calls` entry exists for each bucket with tool calls on a run of the project, and one `harness-events` entry for each kind of harness event.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'limit', description: 'The most rows to return.', in: 'query', schema: new OA\Schema(type: 'integer', default: PageRequest::DEFAULT_LIMIT, maximum: PageRequest::MAX_LIMIT, minimum: 1)),
        new OA\Parameter(name: 'cursor', description: 'The opaque position from the `next` URL of the last page.', in: 'query', schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new SuccessResponse(200, 'One page of metrics.', MetricListResponse::class, page: true),
        new ProblemResponse(400, ['invalid_cursor']),
        new ProblemResponse(404, ['project_not_found']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/metrics',
    name: 'api_metric_list',
    methods: ['GET'],
)]
final class ListMetricsController extends AppController
{
    private const string SORT = 'key';

    public function __construct(
        private readonly ListMetricsHandler $listMetrics,
    ) {
    }

    public function __invoke(Request $request, #[ReachableProject] Project $project): JsonResponse
    {
        $page = PageRequest::fromRequest($request, self::SORT);
        $view = ($this->listMetrics)(new ListMetricsCommand($project));

        $shapes = [
            ...$view->metrics,
            ...array_merge(...array_map(
                static fn (Metric $family): array => array_map(static fn (string $name): MetricShape => new MetricShape(new MetricKey($family, $name)), $view->bucketNames),
                [Metric::BucketTime, Metric::BucketTimeAll, Metric::BucketCalls],
            )),
            ...array_map(static fn (string $kind): MetricShape => new MetricShape(new MetricKey(Metric::HarnessEvents, $kind)), $view->harnessEventKinds),
        ];
        usort($shapes, static fn (MetricShape $a, MetricShape $b): int => strcmp($a->key->key(), $b->key->key()));

        $after = $page->cursor?->sortValue;
        if (null !== $after) {
            $shapes = array_values(array_filter($shapes, static fn (MetricShape $shape): bool => strcmp($shape->key->key(), $after) > 0));
        }

        return Page::fromRows(
            \array_slice($shapes, 0, $page->fetchSize()),
            $page,
            static fn (MetricShape $shape): Cursor => new Cursor(self::SORT, $shape->key->key(), $shape->key->key()),
        )->toResponse($request, static fn (MetricShape $shape): MetricListEntryResponse => new MetricListEntryResponse(
            key: $shape->key->key(),
            units: array_map(static fn (MetricUnit $unit): string => $unit->value, $shape->units()),
            valueType: $shape->valueType()->value,
            statistics: array_map(static fn (MetricStatistic $statistic): string => $statistic->value, $shape->statistics()),
            groups: array_map(static fn (MetricGroup $group): string => $group->value, $shape->groups()),
            description: $shape->description(),
            label: $shape->template?->label,
        ));
    }
}
