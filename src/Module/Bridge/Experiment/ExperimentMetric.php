<?php

declare(strict_types=1);

namespace App\Module\Bridge\Experiment;

use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricShape;
use App\Module\Bridge\Metric\MetricValueType;

/** One row of the comparison: a range per variant, and whether every comparison against the baseline gives a clear answer. */
final readonly class ExperimentMetric
{
    /** The keys the default metrics name, which a template may declare. */
    public const string MERGE_RATE = 'merge-rate';

    public const string STOP_RATE = Metric::StopRate->value;

    public const string FIX_ROUNDS = 'fix-rounds';

    public const string COST = Metric::Cost->value;

    /** The cost of the covered runs. It reads the cost metric, and has a key of its own. */
    public const string COVERED_COST = 'covered-cost';

    public const string OUTPUT_TOKENS = Metric::OutputTokens->value;

    public const string HOURS_TO_MERGE = 'hours-to-merge';

    /**
     * @param array<string, ?Interval>       $byVariant variant name => range, null when the variant has no data
     * @param list<ExperimentMetric>         $parts     the rows under this one, such as the fix rounds of each reason
     * @param ?MetricShape                   $shape     null for a part row
     * @param ?string                        $label     the plain text name of a template metric, or of a part row that has no translation. Null for a built-in metric and a reason part
     * @param array<string, list<int|float>> $samples   variant => the values behind its range, which the headline compares against the baseline
     */
    public function __construct(
        public string $key,
        public array $byVariant,
        /** True when every comparison against the baseline is clear. Null when the experiment has no baseline. */
        public ?bool $clear,
        public array $parts = [],
        public ?MetricShape $shape = null,
        public ?string $label = null,
        public array $samples = [],
    ) {
    }

    public function for(string $variant): ?Interval
    {
        return $this->byVariant[$variant] ?? null;
    }

    /** Null for a part row, which has no metric of its own. */
    public function valueType(): ?MetricValueType
    {
        return $this->shape?->valueType();
    }

    /** How the template shows a value of this row. A part row, such as a fix reason, shows a decimal. */
    public function format(): string
    {
        $type = $this->valueType();

        return match (true) {
            null === $type => 'decimal',
            Metric::Duration === $this->shape?->key->metric => 'minutes',
            default => match ($type) {
                MetricValueType::Money => 'currency',
                MetricValueType::Ratio, MetricValueType::Boolean => 'percent',
                MetricValueType::Tokens => 'integer',
                MetricValueType::Count, MetricValueType::Duration, MetricValueType::Percent => 'decimal',
            },
        };
    }
}
