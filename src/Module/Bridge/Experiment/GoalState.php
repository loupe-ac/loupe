<?php

declare(strict_types=1);

namespace App\Module\Bridge\Experiment;

/** How far the data answers the goal of an experiment, or why the goal cannot be answered. */
enum GoalState: string
{
    case NotEnoughCards = 'not-enough-cards';
    case Clear = 'clear';
    case NotClear = 'not-clear';
    /** The rule names a goal that is not a metric of the project. */
    case UnknownMetric = 'unknown-metric';
    /** The rule names a goal with no better way. */
    case NoDirection = 'no-direction';
}
