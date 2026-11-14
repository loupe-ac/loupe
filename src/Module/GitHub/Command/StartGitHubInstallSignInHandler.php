<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Exception\DomainErrors;
use App\Module\GitHub\Service\GitHubAppConfiguration;
use App\Module\GitHub\Service\GitHubInstallSession;
use App\Module\GitHub\Service\HookSecretKey;

/**
 * Starts the GitHub sign-in of a person who comes back from an install that began on github.com. No project exists
 * yet, and the installation id is only a hint until the person's own installation list holds it.
 */
final readonly class StartGitHubInstallSignInHandler
{
    public function __construct(
        private GitHubAppConfiguration $appConfiguration,
        private GitHubInstallSession $installSession,
        private HookSecretKey $encryptionKey,
    ) {
    }

    /**
     * @return string the OAuth authorize page on GitHub
     *
     * @throws DomainErrors
     */
    public function __invoke(StartGitHubInstallSignInCommand $command): string
    {
        if (!$this->appConfiguration->isConfigured()) {
            throw new DomainErrors(['app' => 'github.app.flash.unavailable']);
        }

        if (!$this->encryptionKey->isReadable()) {
            throw new DomainErrors(['app' => 'github.picker.flash.key_missing']);
        }

        $pending = $this->installSession->beginSignIn(null, null, installationHint: $command->installationHint);

        return $this->appConfiguration->authorizeUrl(
            $command->redirectUri,
            $pending->authorizeState ?? throw new \LogicException('beginSignIn() mints the state'),
            $pending->codeChallenge() ?? throw new \LogicException('beginSignIn() mints the verifier'),
        );
    }
}
