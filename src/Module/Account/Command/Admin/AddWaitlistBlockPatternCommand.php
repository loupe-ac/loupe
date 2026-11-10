<?php

declare(strict_types=1);

namespace App\Module\Account\Command\Admin;

final readonly class AddWaitlistBlockPatternCommand
{
    public function __construct(
        public string $pattern,
    ) {
    }
}
