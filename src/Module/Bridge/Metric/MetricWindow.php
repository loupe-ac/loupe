<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric;

/** One period of a query, from the start (inclusive) to the end (exclusive). A null start is the beginning of time. */
final readonly class MetricWindow
{
    public function __construct(
        public ?\DateTimeImmutable $from,
        public \DateTimeImmutable $to,
    ) {
    }
}
