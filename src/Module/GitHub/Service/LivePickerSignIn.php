<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use Symfony\Component\Uid\Uuid;

/** Whether the browser session holds a live GitHub sign-in for the picker of a project. */
final readonly class LivePickerSignIn
{
    public function __construct(
        private GitHubInstallSession $installSession,
    ) {
    }

    public function existsFor(Uuid $projectId): bool
    {
        return null !== $this->installSession->token((string) $projectId);
    }
}
