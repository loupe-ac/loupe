<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

final readonly class BucketSummaryRow
{
    public function __construct(
        public string $name,
        public int|float $total,
        public int|float|null $median,
        /** The time with subagents summed over the runs that have the figure, null when none has it. */
        public ?int $msWithSubagents,
        /** The calls summed over the runs that have the figure, null when none has it. */
        public ?int $calls,
        /** The share of the time of every listed bucket, 0 when no bucket has time. */
        public float $share,
        /** @var array<string, string>|null the Metrics query of the bucket, null when no run has bucket data */
        public ?array $metricsParams,
    ) {
    }
}
