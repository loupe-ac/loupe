<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Metric\MetricShape;

final readonly class ListMetricsView
{
    /**
     * @param list<MetricShape> $metrics     the metrics whose key says all, the built-in ones first and then those of the workflow template
     * @param list<string>      $bucketNames the buckets with time on the runs of the project
     */
    public function __construct(
        public array $metrics,
        public array $bucketNames,
    ) {
    }
}
