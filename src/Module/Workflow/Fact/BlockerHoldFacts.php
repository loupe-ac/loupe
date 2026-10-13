<?php

declare(strict_types=1);

namespace App\Module\Workflow\Fact;

use App\Module\Workflow\Contract\BlockerHold;

/** The hold of a move rule that an open blocker alone keeps false, as the last pass stamped it. */
final readonly class BlockerHoldFacts
{
    public function __construct(
        public ?BlockerHold $hold,
    ) {
    }
}
