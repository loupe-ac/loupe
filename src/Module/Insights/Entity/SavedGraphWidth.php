<?php

declare(strict_types=1);

namespace App\Module\Insights\Entity;

/** How much of the dashboard row a saved graph takes. The values are stored, so they stay stable. */
enum SavedGraphWidth: string
{
    case Half = 'half';
    case Full = 'full';
}
