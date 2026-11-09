<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller;

use App\Controller\AppController;
use App\Module\Bridge\Command\ListMetricsCommand;
use App\Module\Bridge\Command\ListMetricsHandler;
use App\Module\Bridge\Metric\Formula\FormulaFormat;
use App\Module\Bridge\Metric\Formula\MetricFormula;
use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricCompare;
use App\Module\Bridge\Metric\MetricGroup;
use App\Module\Bridge\Metric\MetricKey;
use App\Module\Bridge\Metric\MetricRange;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricUnit;
use App\Module\Insights\Command\ShowMetricsCommand;
use App\Module\Insights\Command\ShowMetricsHandler;
use App\Module\Insights\Command\ShowSavedGraphCommand;
use App\Module\Insights\Command\ShowSavedGraphHandler;
use App\Module\Insights\Form\SavedGraphFormType;
use App\Module\Insights\Form\SavedGraphRequest;
use App\Module\Insights\Service\FormulaPickerMap;
use App\Module\Insights\Service\SavedGraphCap;
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
    private const array CONTROLS = ['metric', 'unit', 'statistic', 'group', 'range', 'bucket', 'compare', 'chart', 'rows', 'formula', 'format'];

    public function __construct(
        private readonly ShowMetricsHandler $showMetrics,
        private readonly ListMetricsHandler $listMetrics,
        private readonly TemplateMetrics $templateMetrics,
        private readonly ShowSavedGraphHandler $showSavedGraph,
        private readonly SavedGraphCap $savedGraphCap,
    ) {
    }

    public function __invoke(Project $project, Request $request): Response
    {
        $templates = $this->templateMetrics->forProject($project->requireId());
        $query = MetricsQuery::fromQuery($request->query, $templates);
        $saved = ($this->showSavedGraph)(new ShowSavedGraphCommand($project, $request->query->getString('graph')));
        $graph = $saved?->graph;
        $dirty = false;
        if (null !== $saved) {
            // A URL with no control of its own opens the graph as it is saved.
            if ([] === array_intersect_key($request->query->all(), array_flip(self::CONTROLS))) {
                $query = $saved->query;
            }
            $dirty = $query->routeParams() !== $saved->query->routeParams();
        }
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
        $rowsText = $request->query->getString('rows');
        if ('' === trim($rowsText) && $query->formula instanceof MetricFormula) {
            $rowsText = $query->formula->rowsText();
        }
        $rowParts = $query->formula instanceof MetricFormula ? MetricFormula::rowParts($rowsText) : [];
        foreach ($rowParts as $part) {
            $rowKey = MetricKey::tryParse($part['metric'], $templates);
            if (null !== $rowKey && null !== $rowKey->name && \in_array($rowKey->metric, [Metric::BucketTime, Metric::BucketTimeAll, Metric::BucketCalls], true) && !\in_array($rowKey->name, $bucketNames, true)) {
                $bucketNames[] = $rowKey->name;
            }
            if (null !== $rowKey && null !== $rowKey->name && Metric::HarnessEvents === $rowKey->metric && !\in_array($rowKey->name, $eventKinds, true)) {
                $eventKinds[] = $rowKey->name;
            }
        }
        sort($bucketNames, \SORT_STRING);

        $saveForm = $this->createForm(
            SavedGraphFormType::class,
            SavedGraphRequest::fromQuery($query, $view->chart->type, null === $graph ? $view->title : null),
            ['action' => $this->generateUrl('app_project_analytics_save_graph', ['id' => (string) $project->id])],
        );

        return $this->render('@Insights/show_metrics.html.twig', [
            'graph' => $graph,
            'dirty' => $dirty,
            'saveForm' => $saveForm->createView(),
            'capReached' => $this->savedGraphCap->isReached($project),
            'capLimit' => $this->savedGraphCap->limit(),
            'project' => $view->project,
            'query' => $query,
            'view' => $view,
            'metrics' => $list->metrics,
            'bucketNames' => $bucketNames,
            'harnessEventKinds' => $eventKinds,
            'rowParts' => $rowParts,
            'joinable' => FormulaPickerMap::of($list->metrics, $bucketNames, $eventKinds, array_column($rowParts, 'metric'), $templates),
            'statisticCases' => MetricStatistic::cases(),
            'unitCases' => MetricUnit::cases(),
            'groupCases' => MetricGroup::cases(),
            'formatCases' => FormulaFormat::cases(),
            'ranges' => MetricRange::cases(),
            'buckets' => MetricBucket::cases(),
            'compares' => MetricCompare::cases(),
            'chartTypes' => MetricChartType::cases(),
        ]);
    }
}
