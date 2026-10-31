<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricCompare;
use App\Module\Bridge\Metric\MetricGroup;
use App\Module\Bridge\Metric\MetricRange;
use App\Module\Bridge\Metric\MetricShape;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricUnit;
use App\Module\Project\Entity\Project;

final readonly class MetricQueryCommand
{
    public function __construct(
        public Project $project,
        public MetricUnit $unit,
        public MetricShape $metric,
        public MetricStatistic $statistic,
        public MetricGroup $group,
        public MetricRange $range,
        public MetricBucket $bucket,
        public MetricCompare $compare = MetricCompare::Off,
        /** The clock reading both periods count back from. Null reads the clock once. */
        public ?\DateTimeImmutable $now = null,
    ) {
    }
}
