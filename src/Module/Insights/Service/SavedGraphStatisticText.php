<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Module\Bridge\Metric\Formula\MetricFormula;
use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\View\MetricTitle;
use App\Module\Workflow\Contract\TemplateMetrics;
use Symfony\Contracts\Translation\TranslatorInterface;

/** What a settings line prints where the statistic stands: the statistic of a one-metric graph, or the formula in words. */
final readonly class SavedGraphStatisticText
{
    public function __construct(
        private TranslatorInterface $translator,
        private TemplateMetrics $templateMetrics,
        private MetricTitle $metricTitle,
    ) {
    }

    public function of(InsightsSavedGraph $graph): string
    {
        if (null === $graph->formulaRows) {
            return $this->translator->trans('analytics.metrics.statistic.'.$graph->statistic->value);
        }

        $formula = MetricFormula::parse($graph->formulaRows, $graph->formula ?? '', $graph->format ?: 'auto', $this->templateMetrics->forProject($graph->project->requireId()));

        return $this->metricTitle->formulaText($formula);
    }
}
