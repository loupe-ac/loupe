<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

final readonly class StartGitHubInstallSignInCommand
{
    public function __construct(
        /** The installation id from the query of the setup URL. Anyone can forge it, so it is only a hint. */
        public int $installationHint,
        public string $redirectUri,
    ) {
    }
}
