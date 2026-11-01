<?php

declare(strict_types=1);

namespace App\Module\Insights\Entity;

/** Which way a tile moves among the others. The values are route parameters. */
enum SavedGraphDirection: string
{
    case Up = 'up';
    case Down = 'down';
}
