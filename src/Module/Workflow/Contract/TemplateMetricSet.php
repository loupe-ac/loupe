<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** The outcomes and metrics a project's workflow template declares, with the board column of each slot. */
final readonly class TemplateMetricSet
{
    /**
     * @param list<TemplateMetric>  $metrics     in template order
     * @param list<TemplateOutcome> $outcomes    in template order
     * @param array<string, ?Uuid>  $slotColumns the column of each slot key and of `@backlog` and `@terminal`, or null when a slot has no column
     */
    public function __construct(
        public array $metrics = [],
        public array $outcomes = [],
        public array $slotColumns = [],
    ) {
    }

    public function metric(string $key): ?TemplateMetric
    {
        return array_find($this->metrics, static fn (TemplateMetric $metric): bool => $metric->key === $key);
    }

    public function delivered(): ?TemplateOutcome
    {
        return array_find($this->outcomes, static fn (TemplateOutcome $outcome): bool => $outcome->delivered);
    }
}
