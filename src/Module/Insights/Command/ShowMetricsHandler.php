<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Insights\Service\KeptGraphComputer;
use App\Module\Insights\Service\KeptGraphView;
use App\Module\Insights\View\FormulaNotes;
use App\Module\Insights\View\MetricChartRules;
use App\Module\Insights\View\MetricChartType;

final readonly class ShowMetricsHandler
{
    public function __construct(
        private KeptGraphComputer $computer,
        private KeptGraphView $keptView,
    ) {
    }

    public function __invoke(ShowMetricsCommand $command): ShowMetricsView
    {
        $metrics = $this->computer->fetch($command);
        $kept = $this->computer->from($command, $metrics);
        $query = $command->query;
        $built = $this->keptView->build($kept, \Locale::getDefault());
        $lines = \count($kept->series);
        $locks = [];
        foreach (MetricChartType::cases() as $type) {
            $locks[$type->value] = MetricChartRules::lock($type, $query->chartMetric(), $query->chartStatistic(), $lines);
        }

        return new ShowMetricsView($command->project, $metrics, $built->title, $built->chart, $locks, $built->layout, $built->labels, $kept->series, FormulaNotes::of($query->formula, $kept->series));
    }
}
