<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

use App\Module\Bridge\Metric\Formula\FormulaRow;
use App\Module\Bridge\Metric\Formula\MetricFormula;
use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricCompare;
use App\Module\Bridge\Metric\MetricGroup;
use App\Module\Bridge\Metric\MetricRange;
use App\Module\Bridge\Metric\MetricShape;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricUnit;
use Symfony\Contracts\Translation\TranslatorInterface;

/** The graph in words, such as "Cost per finished card, median, by stage, last 90 days, per week". */
final readonly class MetricTitle
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function title(MetricShape $metric, MetricUnit $unit, MetricStatistic $statistic, MetricGroup $group, MetricRange $range, MetricBucket $bucket, MetricCompare $compare = MetricCompare::Off): string
    {
        $parameters = [
            '%subject%' => $this->translator->trans('analytics.metrics.title.subject', [
                '%metric%' => $this->metricName($metric),
                '%unit%' => $this->translator->trans('analytics.metrics.title.unit.'.$unit->value),
            ]),
            '%statistic%' => $this->translator->trans('analytics.metrics.title.statistic.'.$statistic->value),
            '%range%' => $this->range($range, $compare),
            '%bucket%' => $this->translator->trans('analytics.metrics.title.bucket.'.$bucket->value),
        ];
        if (MetricGroup::None === $group) {
            return $this->translator->trans('analytics.metrics.title.plain', $parameters);
        }

        return $this->translator->trans('analytics.metrics.title.grouped', $parameters + ['%group%' => $this->translator->trans('analytics.metrics.title.group.'.$group->value)]);
    }

    /** The title of a query: the formula in words for a formula graph, or else the metric and its statistic. */
    public function forQuery(MetricsQuery $query): string
    {
        $formula = $query->formula;
        if (null === $formula || [] === $formula->rows) {
            return $this->title($query->metric, $query->unit, $query->statistic, $query->group, $query->range, $query->bucket, $query->compare);
        }

        $parameters = [
            '%subject%' => $this->translator->trans('analytics.metrics.title.subject', [
                '%metric%' => $this->formulaText($formula),
                '%unit%' => $this->translator->trans('analytics.metrics.title.unit.'.$query->unit->value),
            ]),
            '%range%' => $this->range($query->range, $query->compare),
            '%bucket%' => $this->translator->trans('analytics.metrics.title.bucket.'.$query->bucket->value),
        ];
        if (MetricGroup::None === $query->group) {
            return $this->translator->trans('analytics.metrics.title.formula_plain', $parameters);
        }

        return $this->translator->trans('analytics.metrics.title.formula_grouped', $parameters + ['%group%' => $this->translator->trans('analytics.metrics.title.group.'.$query->group->value)]);
    }

    /**
     * The formula with each letter replaced by the name of its metric, and the statistic in parentheses when the row
     * is not on Sum, such as "Cache read tokens / Cache write tokens (mean)".
     */
    public function formulaText(MetricFormula $formula): string
    {
        $rows = [];
        foreach ($formula->rows as $row) {
            $rows[$row->letter] = $row;
        }

        return (string) preg_replace_callback(
            '/['.MetricFormula::LETTERS.']/',
            fn (array $match): string => isset($rows[$match[0]]) ? $this->rowName($rows[$match[0]]) : $match[0],
            $formula->formula,
        );
    }

    private function rowName(FormulaRow $row): string
    {
        $name = $this->metricName($row->shape);
        if (MetricStatistic::Sum === $row->statistic) {
            return $name;
        }

        return $this->translator->trans('analytics.metrics.title.formula_row', [
            '%metric%' => $name,
            '%statistic%' => $this->translator->trans('analytics.metrics.title.statistic.'.$row->statistic->value),
        ]);
    }

    /** A template label and a bucket name are text that a person wrote, so the caller prints them escaped. */
    public function metricName(MetricShape $metric): string
    {
        if (null !== $metric->template) {
            return $metric->template->label;
        }
        $key = $metric->key;
        $built = $key->metric ?? throw new \LogicException('A built-in key names a metric.');
        if (null === $key->name) {
            return $this->translator->trans('analytics.metrics.metric.'.$built->value);
        }
        if (Metric::HarnessEvents === $built) {
            return $this->translator->trans('analytics.metrics.metric.harness_events_named', ['%kind%' => $key->name]);
        }

        return $this->translator->trans('analytics.metrics.metric.'.str_replace('-', '_', $built->value).'_named', ['%bucket%' => $key->name]);
    }

    private function range(MetricRange $range, MetricCompare $compare): string
    {
        $days = $range->length()?->d;
        if (null === $days) {
            return $this->translator->trans('analytics.metrics.title.range.all');
        }

        return $this->translator->trans(MetricCompare::Previous === $compare ? 'analytics.metrics.title.range.compared' : 'analytics.metrics.title.range.last', ['%days%' => $days]);
    }
}
