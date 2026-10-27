<?php

declare(strict_types=1);

namespace App\Module\Bridge\Experiment;

use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricValueType;

/** One row of the comparison: a range per variant, and whether the first two variants give a clear answer. */
final readonly class ExperimentMetric
{
    public const string MERGE_RATE = 'merge-rate';

    public const string STOP_RATE = Metric::StopRate->value;

    public const string FIX_ROUNDS = 'fix-rounds';

    public const string COST = Metric::Cost->value;

    public const string OUTPUT_TOKENS = Metric::OutputTokens->value;

    public const string HOURS_TO_MERGE = 'hours-to-merge';

    /**
     * @param array<string, ?Interval> $byVariant variant name => range, null when the variant has no data
     * @param list<ExperimentMetric>   $parts     the rows under this one, such as the fix rounds of each reason
     */
    public function __construct(
        public string $key,
        public array $byVariant,
        public bool $clear,
        public array $parts = [],
    ) {
    }

    public function for(string $variant): ?Interval
    {
        return $this->byVariant[$variant] ?? null;
    }

    /** The value type of the card outcome metrics that an experiment still computes itself. Null for a part row and an unknown key. */
    public static function valueTypeOf(string $key): ?MetricValueType
    {
        return match ($key) {
            self::MERGE_RATE => MetricValueType::Ratio,
            self::FIX_ROUNDS => MetricValueType::Count,
            self::HOURS_TO_MERGE => MetricValueType::Duration,
            default => Metric::tryFrom($key)?->valueType(),
        };
    }

    /** How the template shows a value of this row. A part row, such as a fix reason, has no metric key and shows a decimal. */
    public function format(): string
    {
        $metric = Metric::tryFrom($this->key);
        $type = self::valueTypeOf($this->key);

        return match (true) {
            null === $type => 'decimal',
            Metric::Duration === $metric => 'minutes',
            default => match ($type) {
                MetricValueType::Money => 'currency',
                MetricValueType::Ratio, MetricValueType::Boolean => 'percent',
                MetricValueType::Tokens => 'integer',
                MetricValueType::Count, MetricValueType::Duration => 'decimal',
            },
        };
    }
}
