<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Exception\DomainErrors;
use App\Module\GitHub\Service\GitHubAppConfiguration;
use App\Module\GitHub\Service\GitHubInstallSession;
use App\Module\GitHub\Service\HookSecretKey;
use Symfony\Component\Uid\Uuid;

final readonly class StartGitHubRepositoryPickHandler
{
    public function __construct(
        private GitHubAppConfiguration $appConfiguration,
        private GitHubInstallSession $installSession,
        private HookSecretKey $encryptionKey,
    ) {
    }

    /** @return string the OAuth authorize page on GitHub */
    public function __invoke(StartGitHubRepositoryPickCommand $command): string
    {
        if (!$this->appConfiguration->isConfigured()) {
            throw new DomainErrors(['app' => 'github.app.flash.unavailable']);
        }

        if (!$this->encryptionKey->isReadable()) {
            throw new DomainErrors(['app' => 'github.picker.flash.key_missing']);
        }

        $repository = null !== $command->repository && 1 === preg_match('#^[A-Za-z0-9_.-]{1,100}/[A-Za-z0-9_.-]{1,100}$#', $command->repository) ? $command->repository : null;
        $pending = $this->installSession->beginSignIn($command->project, $repository, 'connect' === $command->startedFrom ? 'connect' : null, null !== $command->init && Uuid::isValid($command->init) ? $command->init : null);

        return $this->appConfiguration->authorizeUrl(
            $command->redirectUri,
            $pending->authorizeState ?? throw new \LogicException('beginSignIn() mints the state'),
            $pending->codeChallenge() ?? throw new \LogicException('beginSignIn() mints the verifier'),
        );
    }
}
