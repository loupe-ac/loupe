<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

use App\Module\Bridge\Command\MetricQueryCommand;
use App\Module\Bridge\Metric\Formula\FormulaFormat;
use App\Module\Bridge\Metric\Formula\MetricFormula;
use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricCompare;
use App\Module\Bridge\Metric\MetricGroup;
use App\Module\Bridge\Metric\MetricKey;
use App\Module\Bridge\Metric\MetricRange;
use App\Module\Bridge\Metric\MetricShape;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricUnit;
use App\Module\Bridge\Metric\MetricValueType;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\TemplateMetricSet;
use Symfony\Component\HttpFoundation\InputBag;

/**
 * The controls of the Metrics page. routeParams() leaves out a control at its
 * default. The GET form still submits every control. All time cannot compare,
 * so it takes Off. A formula graph keeps its earliest row in `metric` and `statistic`,
 * and carries the rows, the formula and the Format as three strings of its own.
 */
final readonly class MetricsQuery
{
    public function __construct(
        public MetricUnit $unit = MetricUnit::Card,
        public MetricShape $metric = new MetricShape(new MetricKey(Metric::Cost)),
        public MetricStatistic $statistic = MetricStatistic::Median,
        public MetricGroup $group = MetricGroup::None,
        public MetricRange $range = MetricRange::NinetyDays,
        public MetricBucket $bucket = MetricBucket::Week,
        public MetricCompare $compare = MetricCompare::Off,
        /** The type the owner asked for. MetricChartRules::resolve() says which one the page draws. */
        public MetricChartType $chart = MetricChartType::Line,
        public ?MetricFormula $formula = null,
    ) {
    }

    /**
     * A kept result that was serialized before the formula existed has no such property. The class is
     * readonly, so the property must be set here or it stays uninitialized and reading it throws.
     *
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $this->unit = $data['unit'];
        $this->metric = $data['metric'];
        $this->statistic = $data['statistic'];
        $this->group = $data['group'];
        $this->range = $data['range'];
        $this->bucket = $data['bucket'];
        $this->compare = $data['compare'];
        $this->chart = $data['chart'];
        $this->formula = $data['formula'] ?? null;
    }

    /** What the chart classes read: the type of a formula result, or the shape of the metric. A formula with a problem has no type. */
    public function chartMetric(): MetricShape|MetricValueType
    {
        return $this->formula?->valueType() ?? $this->metric;
    }

    /** Null for a formula, because the statistic of each row is already in the value. */
    public function chartStatistic(): ?MetricStatistic
    {
        return null === $this->formula ? $this->statistic : null;
    }

    public function command(Project $project, ?\DateTimeImmutable $now = null): MetricQueryCommand
    {
        return new MetricQueryCommand($project, $this->unit, $this->metric, $this->statistic, $this->group, $this->range, $this->bucket, $this->compare, $now, $this->formula);
    }

    /**
     * An unknown value takes the default. A value the metric does not allow
     * takes the default when the metric allows it, or else the first it allows.
     * A template metric the project no longer declares takes the default metric.
     *
     * @param InputBag<string> $query
     */
    public static function fromQuery(InputBag $query, TemplateMetricSet $templates): self
    {
        $formula = self::formulaOf($query, $templates);
        $earliest = $formula?->earliestRow();
        if (null === $earliest) {
            $key = MetricKey::tryParse($query->getString('metric'), $templates) ?? new MetricKey(Metric::Cost);
            $metric = MetricShape::of($key, $templates) ?? new MetricShape(new MetricKey(Metric::Cost));
            $statistic = self::allowed(MetricStatistic::tryFrom($query->getString('statistic')) ?? MetricStatistic::Median, $metric->statistics(), MetricStatistic::Median);
        } else {
            $metric = $earliest->shape;
            $statistic = $earliest->statistic;
        }

        $range = MetricRange::tryFrom($query->getString('range')) ?? MetricRange::NinetyDays;

        return new self(
            unit: self::allowed(MetricUnit::tryFrom($query->getString('unit')) ?? MetricUnit::Card, $formula?->units() ?: $metric->units()),
            metric: $metric,
            statistic: $statistic,
            group: self::allowed(MetricGroup::tryFrom($query->getString('group')) ?? MetricGroup::None, $formula?->groups() ?: $metric->groups(), MetricGroup::None),
            range: $range,
            bucket: self::allowed(MetricBucket::tryFrom($query->getString('bucket')) ?? MetricBucket::Week, $range->allowedBuckets()),
            compare: null === $range->length() ? MetricCompare::Off : (MetricCompare::tryFrom($query->getString('compare')) ?? MetricCompare::Off),
            chart: MetricChartType::tryFrom($query->getString('chart')) ?? MetricChartType::default(),
            formula: $formula,
        );
    }

    /**
     * A formula has rows, or a formula text. A one-metric query has neither.
     *
     * @param InputBag<string> $query
     */
    private static function formulaOf(InputBag $query, TemplateMetricSet $templates): ?MetricFormula
    {
        $rows = $query->getString('rows');
        $formula = $query->getString('formula');
        if ('' === trim($rows) && '' === trim($formula)) {
            return null;
        }
        $format = $query->getString('format');

        return MetricFormula::parse($rows, $formula, '' === $format ? FormulaFormat::Auto->value : $format, $templates);
    }

    /** @return array{unit?: string, metric?: string, statistic?: string, group?: string, range?: string, bucket?: string, compare?: string, chart?: string, rows?: string, formula?: string, format?: string} */
    public function routeParams(): array
    {
        $params = [];
        if (MetricUnit::Card !== $this->unit) {
            $params['unit'] = $this->unit->value;
        }
        if (null === $this->formula) {
            if (Metric::Cost->value !== $this->metric->key->key()) {
                $params['metric'] = $this->metric->key->key();
            }
            if (MetricStatistic::Median !== $this->statistic) {
                $params['statistic'] = $this->statistic->value;
            }
        }
        if (MetricGroup::None !== $this->group) {
            $params['group'] = $this->group->value;
        }
        if (MetricRange::NinetyDays !== $this->range) {
            $params['range'] = $this->range->value;
        }
        if (MetricBucket::Week !== $this->bucket) {
            $params['bucket'] = $this->bucket->value;
        }
        if (MetricCompare::Off !== $this->compare) {
            $params['compare'] = $this->compare->value;
        }
        if (MetricChartType::default() !== $this->chart) {
            $params['chart'] = $this->chart->value;
        }
        if (null !== $this->formula) {
            $params['rows'] = $this->formula->rowsText();
            $params['formula'] = $this->formula->formula;
            if (FormulaFormat::Auto !== $this->formula->format) {
                $params['format'] = $this->formula->format->value;
            }
        }

        return $params;
    }

    /**
     * @template T of \UnitEnum
     *
     * @param T       $requested
     * @param list<T> $allowed
     * @param ?T      $preferred
     *
     * @return T
     */
    private static function allowed(\UnitEnum $requested, array $allowed, ?\UnitEnum $preferred = null): \UnitEnum
    {
        if (\in_array($requested, $allowed, true)) {
            return $requested;
        }
        if (null !== $preferred && \in_array($preferred, $allowed, true)) {
            return $preferred;
        }

        return $allowed[0] ?? throw new \LogicException('A metric allows at least one value of each control.');
    }
}
