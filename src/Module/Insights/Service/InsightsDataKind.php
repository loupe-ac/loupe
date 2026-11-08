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

    /** Not a kind of data: the sweep reads the items that are older than the interval. */
    case Sweep = 'sweep';

    /** Not a kind of data: a view of a dormant project reads every item. */
    case Wake = 'wake';

    /** Whether a message of this kind comes from a write of new data. */
    public function isNewData(): bool
    {
        return !\in_array($this, [self::Sweep, self::Wake], true);
    }
}
