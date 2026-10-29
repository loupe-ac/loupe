<?php

declare(strict_types=1);

namespace App\Module\Bridge\Experiment;

/** Which way of the goal metric is better. */
enum GoalDirection: string
{
    case Lower = 'lower';
    case Higher = 'higher';
}
