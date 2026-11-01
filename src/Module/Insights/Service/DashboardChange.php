<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

/** What happened to a saved graph. The values travel in the live signal, so they stay stable. */
enum DashboardChange: string
{
    case Saved = 'saved';
    case Updated = 'updated';
    case Renamed = 'renamed';
    case Resized = 'resized';
    case LegendChanged = 'legend-changed';
    case Moved = 'moved';
    case Removed = 'removed';
    case Restored = 'restored';
}
