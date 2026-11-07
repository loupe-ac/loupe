<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

/** A kind of data that a graph or a shared experiment reads. The value is part of the dedupe key, so it stays stable. */
enum InsightsDataKind: string
{
    case Runs = 'runs';
    case BucketTimes = 'bucket-times';
    case Cards = 'cards';
    case Readings = 'readings';
    case Experiment = 'experiment';
}
