<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

/** What a saved graph names that the project no longer has. */
enum BrokenGraphReason: string
{
    case TemplateMetric = 'template-metric';
    case Bucket = 'bucket';
    case HarnessEvent = 'harness-event';
}
