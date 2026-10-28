<?php

declare(strict_types=1);

namespace App\Module\Bridge\Experiment;

/** How far the data answers the goal of an experiment. */
enum GoalState: string
{
    case NotEnoughCards = 'not-enough-cards';
    case Clear = 'clear';
    case NotClear = 'not-clear';
}
