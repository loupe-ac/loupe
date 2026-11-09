<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric\Formula;

use App\Module\Bridge\Metric\MetricShape;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricValueType;

/** One lettered row of a formula: a metric and the statistic that reads it for each bucket and group. */
final readonly class FormulaRow
{
    public function __construct(
        public string $letter,
        public MetricShape $shape,
        public MetricStatistic $statistic,
    ) {
    }

    /** A row on Count is Count, and any other row takes the type of its metric. */
    public function valueType(): MetricValueType
    {
        return MetricStatistic::Count === $this->statistic ? MetricValueType::Count : $this->shape->valueType();
    }

    /** The item of the rows text, such as `A:cache-read-tokens:sum`. */
    public function text(): string
    {
        return $this->letter.':'.$this->shape->key->key().':'.$this->statistic->value;
    }
}
