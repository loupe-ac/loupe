<?php

declare(strict_types=1);

namespace App\Module\Inbox\Workflow;

/** Whether the owner changed a decision of the card that no worker acted on yet. */
final readonly class DecisionChangedFacts
{
    public function __construct(
        public bool $open,
    ) {
    }
}
