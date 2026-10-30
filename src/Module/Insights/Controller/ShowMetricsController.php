<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller;

use App\Controller\AppController;
use App\Module\Bridge\Command\ListMetricsCommand;
use App\Module\Bridge\Command\ListMetricsHandler;
use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricCompare;
use App\Module\Bridge\Metric\MetricRange;
use App\Module\Insights\Command\ShowMetricsCommand;
use App\Module\Insights\Command\ShowMetricsHandler;
use App\Module\Insights\View\MetricChartType;
use App\Module\Insights\View\MetricsQuery;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Workflow\Contract\TemplateMetrics;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/analytics/metrics',
    name: 'app_project_analytics_metrics',
    methods: ['GET'],
)]
class ShowMetricsController extends AppController
{
    public function __construct(
        private readonly ShowMetricsHandler $showMetrics,
        private readonly ListMetricsHandler $listMetrics,
        private readonly TemplateMetrics $templateMetrics,
    ) {
    }

    public function __invoke(Project $project, Request $request): Response
    {
        $query = MetricsQuery::fromQuery($request->query, $this->templateMetrics->forProject($project->requireId()));
        $view = ($this->showMetrics)(new ShowMetricsCommand($project, $query));
        $list = ($this->listMetrics)(new ListMetricsCommand($project));
        $bucketNames = $list->bucketNames;
        $eventKinds = $list->harnessEventKinds;
        $selected = $query->metric->key->name;
        // A valid name with no data on any run still reads as 0, so the picker keeps it.
        if (null !== $selected && \in_array($query->metric->key->metric, [Metric::BucketTime, Metric::BucketTimeAll, Metric::BucketCalls], true) && !\in_array($selected, $bucketNames, true)) {
            $bucketNames[] = $selected;
        }
        if (null !== $selected && Metric::HarnessEvents === $query->metric->key->metric && !\in_array($selected, $eventKinds, true)) {
            $eventKinds[] = $selected;
        }
        sort($bucketNames, \SORT_STRING);

        return $this->render('@Insights/show_metrics.html.twig', [
            'project' => $view->project,
            'query' => $query,
            'view' => $view,
            'metrics' => $list->metrics,
            'bucketNames' => $bucketNames,
            'harnessEventKinds' => $eventKinds,
            'ranges' => MetricRange::cases(),
            'buckets' => MetricBucket::cases(),
            'compares' => MetricCompare::cases(),
            'chartTypes' => MetricChartType::cases(),
        ]);
    }
}
