<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricCompare;
use App\Module\Bridge\Metric\MetricGroup;
use App\Module\Bridge\Metric\MetricKey;
use App\Module\Bridge\Metric\MetricRange;
use App\Module\Bridge\Metric\MetricShape;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricUnit;
use App\Module\Workflow\Contract\TemplateMetricSet;
use Symfony\Component\HttpFoundation\InputBag;

/**
 * The controls of the Metrics page. routeParams() leaves out a control at its
 * default. The GET form still submits every control. All time cannot compare,
 * so it takes Off.
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
    ) {
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
        $key = MetricKey::tryParse($query->getString('metric'), $templates) ?? new MetricKey(Metric::Cost);
        $metric = MetricShape::of($key, $templates) ?? new MetricShape(new MetricKey(Metric::Cost));

        $range = MetricRange::tryFrom($query->getString('range')) ?? MetricRange::NinetyDays;

        return new self(
            unit: self::allowed(MetricUnit::tryFrom($query->getString('unit')) ?? MetricUnit::Card, $metric->units()),
            metric: $metric,
            statistic: self::allowed(MetricStatistic::tryFrom($query->getString('statistic')) ?? MetricStatistic::Median, $metric->statistics(), MetricStatistic::Median),
            group: self::allowed(MetricGroup::tryFrom($query->getString('group')) ?? MetricGroup::None, $metric->groups(), MetricGroup::None),
            range: $range,
            bucket: self::allowed(MetricBucket::tryFrom($query->getString('bucket')) ?? MetricBucket::Week, $range->allowedBuckets()),
            compare: null === $range->length() ? MetricCompare::Off : (MetricCompare::tryFrom($query->getString('compare')) ?? MetricCompare::Off),
            chart: MetricChartType::tryFrom($query->getString('chart')) ?? MetricChartType::default(),
        );
    }

    /** @return array{unit?: string, metric?: string, statistic?: string, group?: string, range?: string, bucket?: string, compare?: string, chart?: string} */
    public function routeParams(): array
    {
        $params = [];
        if (MetricUnit::Card !== $this->unit) {
            $params['unit'] = $this->unit->value;
        }
        if (Metric::Cost->value !== $this->metric->key->key()) {
            $params['metric'] = $this->metric->key->key();
        }
        if (MetricStatistic::Median !== $this->statistic) {
            $params['statistic'] = $this->statistic->value;
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
