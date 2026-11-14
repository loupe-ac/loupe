<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Module\GitHub\Service\GitHubInstallSession;
use Symfony\Component\DependencyInjection\Attribute\When;

/** Keeps a preview token as the sign-in of an install that began on github.com, with no project. It exists in dev only. */
#[When('dev')]
final readonly class PreviewGitHubInstallChoiceHandler
{
    public function __construct(
        private GitHubInstallSession $installSession,
    ) {
    }

    public function __invoke(PreviewGitHubInstallChoiceCommand $command): void
    {
        $this->installSession->signIn(
            $this->installSession->beginSignIn(null, null, installationHint: $command->installationId),
            $command->token,
        );
    }
}
