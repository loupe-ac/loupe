<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Metric\MetricSeries;
use App\Module\Bridge\Metric\MetricWindow;

final readonly class MetricQueryView
{
    /**
     * @param list<MetricSeries> $series   by group, the series with no group last
     * @param ?MetricWindow      $previous null when the query does not compare
     */
    public function __construct(
        public MetricQueryCommand $query,
        public array $series,
        public MetricWindow $current,
        public ?MetricWindow $previous = null,
    ) {
    }
}
