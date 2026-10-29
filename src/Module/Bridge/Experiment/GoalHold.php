<?php

declare(strict_types=1);

namespace App\Module\Bridge\Experiment;

/** Why the goal names no winner: the first kind of control result that holds it, and the controls with that result. */
final readonly class GoalHold
{
    public function __construct(
        public ControlResult $reason,
        /** @var list<ExperimentControl> */
        public array $controls,
        /** The number of controls the experiment declares. */
        public int $total,
    ) {
    }
}
