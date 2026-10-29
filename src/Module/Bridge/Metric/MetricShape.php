<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric;

use App\Module\Workflow\Contract\MetricType;
use App\Module\Workflow\Contract\TemplateMetric;
use App\Module\Workflow\Contract\TemplateMetricSet;

/**
 * What a query may ask of a metric: its units, value type, statistics and groups. A built-in key reads
 * them from `Metric`. A template metric is a card metric that counts a card once, and a template
 * duration is in hours.
 */
final readonly class MetricShape
{
    public function __construct(
        public MetricKey $key,
        public ?TemplateMetric $template = null,
    ) {
        if ($key->isTemplate() !== (null !== $template) || (null !== $template && $template->key !== $key->templateKey)) {
            throw new \InvalidArgumentException('A template key and its template metric go together.');
        }
    }

    /** Null when the key names a template metric that the set does not hold. */
    public static function of(MetricKey $key, TemplateMetricSet $templates): ?self
    {
        if (!$key->isTemplate()) {
            return new self($key);
        }
        $template = $templates->metric($key->templateKey ?? '');

        return null === $template ? null : new self($key, $template);
    }

    public static function template(TemplateMetric $template): self
    {
        return new self(MetricKey::template($template->key), $template);
    }

    /** True for a metric that the workflow template declares. */
    public function isTemplateCardMetric(): bool
    {
        return null !== $this->template;
    }

    /** True when a value is in hours, where a built-in duration is in milliseconds. */
    public function isInHours(): bool
    {
        return MetricType::Duration === $this->template?->type;
    }

    /** @return list<MetricUnit> */
    public function units(): array
    {
        return null === $this->template ? $this->builtIn()->units() : [MetricUnit::Card];
    }

    public function valueType(): MetricValueType
    {
        return null === $this->template ? $this->builtIn()->valueType() : match ($this->template->type) {
            MetricType::Count => MetricValueType::Count,
            MetricType::Rate => MetricValueType::Ratio,
            MetricType::Duration => MetricValueType::Duration,
        };
    }

    /** @return list<MetricStatistic> */
    public function statistics(): array
    {
        return $this->valueType()->statistics();
    }

    /** @return list<MetricGroup> */
    public function groups(): array
    {
        return null === $this->template ? $this->builtIn()->groups() : [MetricGroup::Variant, MetricGroup::CardType, MetricGroup::None];
    }

    /** A sum over the runs of a card gives its value on the card unit. */
    public function isAdditive(): bool
    {
        return null === $this->template && $this->builtIn()->isAdditive();
    }

    public function description(): string
    {
        return null === $this->template ? $this->builtIn()->description() : $this->template->description;
    }

    private function builtIn(): Metric
    {
        return $this->key->metric ?? throw new \LogicException('A template metric has no built-in metric.');
    }
}
