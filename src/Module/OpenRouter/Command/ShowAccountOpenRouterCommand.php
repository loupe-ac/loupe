<?php

declare(strict_types=1);

namespace App\Module\OpenRouter\Command;

use App\Module\Account\Entity\User;

final readonly class ShowAccountOpenRouterCommand
{
    public function __construct(
        public User $user,
    ) {
    }
}
