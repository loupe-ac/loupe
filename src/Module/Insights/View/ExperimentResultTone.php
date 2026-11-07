<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

/** Which way a change goes against the better direction of the goal. */
enum ExperimentResultTone: string
{
    case Better = 'better';
    case Worse = 'worse';
    case Level = 'level';
    case None = 'none';
}
