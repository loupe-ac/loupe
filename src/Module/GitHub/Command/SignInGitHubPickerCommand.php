<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Module\GitHub\Service\PendingGitHubInstall;

final readonly class SignInGitHubPickerCommand
{
    public function __construct(
        public PendingGitHubInstall $pending,
        public string $code,
        public string $redirectUri,
    ) {
    }
}
