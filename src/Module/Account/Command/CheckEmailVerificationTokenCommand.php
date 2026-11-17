<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

final readonly class CheckEmailVerificationTokenCommand
{
    public function __construct(
        public ?string $token,
    ) {
    }
}
