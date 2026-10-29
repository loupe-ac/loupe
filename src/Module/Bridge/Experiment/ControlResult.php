<?php

declare(strict_types=1);

namespace App\Module\Bridge\Experiment;

/** What a control says about the treatment of one variant. */
enum ControlResult: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    /** No finished card of the variant has a value for the metric. */
    case NoData = 'no-data';
    /** The key is not a metric of the project. */
    case UnknownMetric = 'unknown-metric';

    /** True for a result that holds the goal back. */
    public function holds(): bool
    {
        return self::Passed !== $this;
    }
}
