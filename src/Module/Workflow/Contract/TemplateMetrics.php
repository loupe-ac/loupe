<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** Bridge and Analytics ask this port for the metrics of a project, and the workflow template of the project declares them. */
interface TemplateMetrics
{
    public function forProject(Uuid $projectId): TemplateMetricSet;
}
