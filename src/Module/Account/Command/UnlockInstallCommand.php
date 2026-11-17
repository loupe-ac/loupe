<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use Symfony\Component\HttpFoundation\Session\SessionInterface;

final readonly class UnlockInstallCommand
{
    public function __construct(
        public SessionInterface $session,
        public string $token,
    ) {
    }
}
