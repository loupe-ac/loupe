<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** An outcome that a move of the template may set. The delivered outcome marks the cards whose work reached the user. */
final readonly class TemplateOutcome
{
    public function __construct(
        public string $key,
        public bool $delivered = false,
    ) {
    }
}
