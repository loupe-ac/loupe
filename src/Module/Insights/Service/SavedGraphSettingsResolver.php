<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Exception\DomainErrors;
use App\Module\Bridge\Command\MetricQueryCommand;
use App\Module\Bridge\Command\MetricQueryHandler;
use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\View\MetricChartRules;
use App\Module\Insights\View\MetricsQuery;
use App\Module\Insights\View\MetricTitle;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\TemplateMetrics;
use Symfony\Component\HttpFoundation\InputBag;

/** Turns the posted controls of the Metrics page into settings that the page itself would draw. */
final readonly class SavedGraphSettingsResolver
{
    public const string CHART_LOCKED_PREFIX = 'insights.saved_graph.error.chart_';

    public function __construct(
        private TemplateMetrics $templateMetrics,
        private MetricQueryHandler $metricQuery,
        private MetricTitle $metricTitle,
    ) {
    }

    /**
     * The controls take the same fallbacks as the Metrics page. A chart type that the settings lock is refused.
     *
     * @param array<string, string> $settings
     *
     * @throws DomainErrors on the field `chart` when the settings lock the chart type
     */
    public function resolve(Project $project, array $settings): MetricsQuery
    {
        /** @var InputBag<string> $posted */
        $posted = new InputBag($settings);
        $query = MetricsQuery::fromQuery($posted, $this->templateMetrics->forProject($project->requireId()));
        $view = ($this->metricQuery)(new MetricQueryCommand($project, $query->unit, $query->metric, $query->statistic, $query->group, $query->range, $query->bucket, $query->compare));
        $lock = MetricChartRules::lock($query->chart, $query->metric, $query->statistic, \count($view->series));
        if (null !== $lock) {
            throw new DomainErrors(['chart' => self::CHART_LOCKED_PREFIX.str_replace('-', '_', $lock->value)]);
        }

        return $query;
    }

    /** A blank name takes the title of the graph, cut to the column. */
    public function name(?string $posted, MetricsQuery $query): string
    {
        $name = trim($posted ?? '');
        if ('' === $name) {
            $name = $this->metricTitle->title($query->metric, $query->unit, $query->statistic, $query->group, $query->range, $query->bucket, $query->compare);
        }

        return mb_substr($name, 0, InsightsSavedGraph::MAX_NAME_LENGTH);
    }
}
