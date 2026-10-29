<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Workflow\Contract\MetricType;
use App\Module\Workflow\Contract\TemplateMetric;
use App\Module\Workflow\Contract\TemplateMetricSet;

/** Template metrics for tests that need a project whose workflow template declares some. */
final class TemplateMetricSets
{
    public static function rate(string $key = 'merge-rate'): TemplateMetric
    {
        return new TemplateMetric($key, 'Merge rate', 'The share of cards that merged.', MetricType::Rate, outcome: 'delivered');
    }

    public static function count(string $key = 'fix-rounds'): TemplateMetric
    {
        return new TemplateMetric($key, 'Fix rounds', 'The fix rounds of a card.', MetricType::Count);
    }

    public static function duration(string $key = 'hours-to-merge'): TemplateMetric
    {
        return new TemplateMetric($key, 'Hours to merge', 'The hours from the first pull request to the merge.', MetricType::Duration);
    }

    public static function all(): TemplateMetricSet
    {
        return new TemplateMetricSet([self::rate(), self::count(), self::duration()]);
    }
}
