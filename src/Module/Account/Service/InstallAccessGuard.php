<?php

declare(strict_types=1);

namespace App\Module\Account\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Gates the install wizard behind a deploy-time INSTALL_TOKEN, on top of
 * InstallationState::isOpen(). An unset/empty token preserves the previous
 * "first visitor wins" behaviour dev, test, and worktree provisioning depend
 * on. A set token is submitted once through the unlock form and remembered for
 * the rest of the session. It never travels in the URL, so the access log and
 * the browser history never hold it.
 */
final readonly class InstallAccessGuard
{
    private const string SESSION_TOKEN_VERIFIED = 'install_token_verified';

    public function __construct(
        private InstallationState $installationState,

        #[Autowire(env: 'INSTALL_TOKEN')]
        private string $installToken,

        #[Autowire('%kernel.environment%')]
        private string $environment,
    ) {
    }

    /**
     * 404s while the wizard is closed, so an installed instance shows nothing.
     */
    public function ensureOpen(): void
    {
        if (!$this->installationState->isOpen()) {
            throw new NotFoundHttpException();
        }

        // Unconfigured fails CLOSED in production: a forgotten env var must
        // not leave an admin-minting endpoint publicly reachable. Elsewhere
        // it stays open, because dev, the test suite and every
        // `just worktree-up` run the wizard unattended.
        if ('' === $this->installToken && 'prod' === $this->environment) {
            throw new NotFoundHttpException();
        }
    }

    public function isUnlocked(Request $request): bool
    {
        if ('' === $this->installToken) {
            return true;
        }

        return true === $request->getSession()->get(self::SESSION_TOKEN_VERIFIED);
    }

    public function unlock(Request $request, string $submitted): bool
    {
        if ('' === $this->installToken || !hash_equals($this->installToken, $submitted)) {
            return false;
        }

        $session = $request->getSession();
        // A new session id means an id that an attacker planted before the
        // unlock does not gain the flag.
        $session->migrate(true);
        $session->set(self::SESSION_TOKEN_VERIFIED, true);

        return true;
    }
}
