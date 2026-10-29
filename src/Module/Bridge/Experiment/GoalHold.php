<?php

declare(strict_types=1);

namespace App\Module\Bridge\Experiment;

/** Why a comparison names no result: the first kind of control result that holds it, and the controls with that result. */
final readonly class GoalHold
{
    public function __construct(
        public ControlResult $reason,
        /** @var list<ExperimentControl> */
        public array $controls,
        /** The number of controls on the two arms of the comparison. */
        public int $total,
    ) {
    }
}
