<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Module\GitHub\Service\GitHubInstallSession;
use Symfony\Component\DependencyInjection\Attribute\When;

/** Keeps a preview token as the picker sign-in of the browser session. It exists in dev only. */
#[When('dev')]
final readonly class PreviewGitHubPickerSignInHandler
{
    public function __construct(
        private GitHubInstallSession $installSession,
    ) {
    }

    public function __invoke(PreviewGitHubPickerSignInCommand $command): void
    {
        $this->installSession->signIn($this->installSession->beginSignIn($command->project, null), $command->token);
    }
}
