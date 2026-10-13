<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Workflow\Contract\PauseView;

/** The active pause of the card, null when the card has none. */
final readonly class PauseFacts
{
    public function __construct(
        public ?PauseView $active,
    ) {
    }
}
