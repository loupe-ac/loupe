<?php

declare(strict_types=1);

namespace App\Module\Insights\Form;

use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\View\MetricChartType;
use App\Module\Insights\View\MetricsQuery;
use Symfony\Component\Validator\Constraints as Assert;

class SavedGraphRequest
{
    public function __construct(
        #[Assert\Length(max: InsightsSavedGraph::MAX_NAME_LENGTH)]
        public ?string $name = null,
        public ?string $metric = null,
        public ?string $unit = null,
        public ?string $statistic = null,
        public ?string $group = null,
        public ?string $range = null,
        public ?string $bucket = null,
        public ?string $compare = null,
        public ?string $chart = null,
    ) {
    }

    /** Every setting explicit, with the chart type the page draws, so a save never meets a lock the page already avoided. */
    public static function fromQuery(MetricsQuery $query, MetricChartType $drawn, ?string $name = null): self
    {
        return new self(
            $name,
            $query->metric->key->key(),
            $query->unit->value,
            $query->statistic->value,
            $query->group->value,
            $query->range->value,
            $query->bucket->value,
            $query->compare->value,
            $drawn->value,
        );
    }

    /**
     * The posted settings under the names the Metrics page reads. A control left out stays out.
     *
     * @return array<string, string>
     */
    public function settings(): array
    {
        return array_filter([
            'metric' => $this->metric,
            'unit' => $this->unit,
            'statistic' => $this->statistic,
            'group' => $this->group,
            'range' => $this->range,
            'bucket' => $this->bucket,
            'compare' => $this->compare,
            'chart' => $this->chart,
        ], static fn (?string $value): bool => null !== $value && '' !== $value);
    }
}
