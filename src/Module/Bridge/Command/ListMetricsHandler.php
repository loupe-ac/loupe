<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricKey;
use App\Module\Bridge\Metric\MetricShape;
use App\Module\Bridge\Repository\WorkerRunBucketTimeRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Workflow\Contract\TemplateMetrics;

final readonly class ListMetricsHandler
{
    public function __construct(
        private WorkerRunBucketTimeRepository $workerRunBucketTimes,
        private WorkerRunRepository $workerRuns,
        private TemplateMetrics $templateMetrics,
    ) {
    }

    public function __invoke(ListMetricsCommand $command): ListMetricsView
    {
        $metrics = [
            ...array_map(static fn (Metric $metric): MetricShape => new MetricShape(new MetricKey($metric)), Metric::standalone()),
            ...array_map(MetricShape::template(...), $this->templateMetrics->forProject($command->project->requireId())->metrics),
        ];

        return new ListMetricsView($metrics, $this->workerRunBucketTimes->findBucketNamesOfProject($command->project), $this->workerRuns->findHarnessEventKindsOfProject($command->project));
    }
}
