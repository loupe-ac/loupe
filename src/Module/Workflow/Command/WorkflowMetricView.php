<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

final readonly class WorkflowMetricView
{
    /**
     * @param string $label       plain English text from the template, not a translation key
     * @param string $description plain English text from the template, not a translation key
     * @param string $type        a MetricType value
     * @param string $reads       the translated sentence that says what the metric reads
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public string $type,
        public string $reads,
    ) {
    }
}
