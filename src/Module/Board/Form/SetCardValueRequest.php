<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

final class SetCardValueRequest
{
    public function __construct(
        /** The state the switch takes, not a flip, so a second submit of a stale page changes nothing. */
        public bool $value = false,
    ) {
    }
}
