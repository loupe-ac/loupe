<?php

declare(strict_types=1);

namespace App\Module\Account\Command\Admin;

use App\Module\Account\Entity\WaitlistBlockPattern;

final readonly class RemoveWaitlistBlockPatternCommand
{
    public function __construct(
        public WaitlistBlockPattern $pattern,
    ) {
    }
}
