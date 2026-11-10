<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Project\Entity\Project;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Ubermuda\DoctrineExtra\Encryption\Encryptor;

/**
 * The picker session. It carries a trip to GitHub, then keeps the owner's user
 * token, encrypted, for a short time. One picker session per browser session:
 * a new one replaces the old, token included. Only token() returns the token,
 * and it checks the sign-in time first.
 */
final readonly class GitHubInstallSession
{
    public const int TOKEN_LIFETIME_SECONDS = 1800;

    private const string KEY = 'github_app_install';

    public function __construct(
        private RequestStack $requestStack,
        private Encryptor $encryptor,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /** @return string the state to send to GitHub with the install link */
    public function beginInstall(Project $project): string
    {
        $pending = new PendingGitHubInstall((string) $project->id, bin2hex(random_bytes(16)));
        $this->store($pending);

        return $pending->installState ?? throw new \LogicException('An install pending holds its state.');
    }

    /** Mints the state and the PKCE verifier of a sign-in. */
    public function beginSignIn(Project $project, ?string $repository, ?string $startedFrom = null): PendingGitHubInstall
    {
        $pending = new PendingGitHubInstall(
            (string) $project->id,
            authorizeState: bin2hex(random_bytes(16)),
            codeVerifier: self::newVerifier(),
            repository: $repository,
            startedFrom: $startedFrom,
        );
        $this->store($pending);

        return $pending;
    }

    public function pending(): ?PendingGitHubInstall
    {
        $data = $this->requestStack->getSession()->get(self::KEY);
        if (!\is_array($data) || !\is_string($data['projectId'] ?? null)) {
            return null;
        }

        return new PendingGitHubInstall(
            $data['projectId'],
            \is_string($data['installState'] ?? null) ? $data['installState'] : null,
            \is_int($data['installationId'] ?? null) ? $data['installationId'] : null,
            \is_string($data['authorizeState'] ?? null) ? $data['authorizeState'] : null,
            \is_string($data['codeVerifier'] ?? null) ? $data['codeVerifier'] : null,
            \is_string($data['repository'] ?? null) ? $data['repository'] : null,
            \is_string($data['encryptedToken'] ?? null) ? $data['encryptedToken'] : null,
            \is_int($data['signedInAt'] ?? null) ? $data['signedInAt'] : null,
            \is_string($data['startedFrom'] ?? null) ? $data['startedFrom'] : null,
        );
    }

    /** Binds the installation GitHub named, and mints the state and the PKCE verifier of the authorization. */
    public function authorize(PendingGitHubInstall $pending, int $installationId): PendingGitHubInstall
    {
        $authorizing = new PendingGitHubInstall(
            $pending->projectId,
            $pending->installState,
            $installationId,
            bin2hex(random_bytes(16)),
            self::newVerifier(),
            $pending->repository,
            startedFrom: $pending->startedFrom,
        );
        $this->store($authorizing);

        return $authorizing;
    }

    /** Keeps the token encrypted with the time of the sign-in, and drops the state of the trip. */
    public function signIn(PendingGitHubInstall $pending, #[\SensitiveParameter] string $token): void
    {
        $this->store(new PendingGitHubInstall(
            $pending->projectId,
            repository: $pending->repository,
            encryptedToken: $this->encryptor->encrypt($token),
            signedInAt: $this->clock->now()->getTimestamp(),
            startedFrom: $pending->startedFrom,
        ));
    }

    /**
     * The token of a live sign-in for this project. After 30 minutes, or when
     * the key cannot open it, the token is refused and removed with the rest of
     * the session, so the owner signs in again.
     */
    public function token(string $projectId): ?string
    {
        $pending = $this->pending();
        if (null === $pending || $pending->projectId !== $projectId || null === $pending->encryptedToken || null === $pending->signedInAt) {
            return null;
        }

        if ($this->clock->now()->getTimestamp() - $pending->signedInAt >= self::TOKEN_LIFETIME_SECONDS) {
            $this->clear();
            $this->logger->info('github.picker_token_expired', ['projectId' => $projectId]);

            return null;
        }

        try {
            return $this->encryptor->decrypt($pending->encryptedToken)->plain;
        } catch (\RuntimeException) {
            $this->clear();
            $this->logger->warning('github.picker_token_unreadable', ['projectId' => $projectId]);

            return null;
        }
    }

    public function clear(): void
    {
        $this->requestStack->getSession()->remove(self::KEY);
    }

    private function store(PendingGitHubInstall $pending): void
    {
        $this->requestStack->getSession()->set(self::KEY, [
            'projectId' => $pending->projectId,
            'installState' => $pending->installState,
            'installationId' => $pending->installationId,
            'authorizeState' => $pending->authorizeState,
            'codeVerifier' => $pending->codeVerifier,
            'repository' => $pending->repository,
            'encryptedToken' => $pending->encryptedToken,
            'signedInAt' => $pending->signedInAt,
            'startedFrom' => $pending->startedFrom,
        ]);
    }

    private static function newVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
