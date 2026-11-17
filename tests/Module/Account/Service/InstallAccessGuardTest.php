<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Service;

use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Service\InstallAccessGuard;
use App\Module\Account\Service\InstallationState;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class InstallAccessGuardTest extends TestCase
{
    private function requestWithSession(): Request
    {
        $request = Request::create('/install');
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    private function installationState(int $userCount): InstallationState
    {
        /** @var UserRepository&Stub $users */
        $users = $this->createStub(UserRepository::class);
        $users->method('countHumans')->willReturn($userCount);

        return new InstallationState($users);
    }

    public function test_closed_installation_404s_regardless_of_token(): void
    {
        $guard = new InstallAccessGuard($this->installationState(1), '', 'test');

        $this->expectException(NotFoundHttpException::class);
        $guard->ensureOpen();
    }

    public function test_open_installation_with_no_configured_token_is_unlocked(): void
    {
        $guard = new InstallAccessGuard($this->installationState(0), '', 'test');

        $guard->ensureOpen();
        self::assertTrue($guard->isUnlocked($this->requestWithSession()));
    }

    public function test_configured_token_is_locked_until_unlocked(): void
    {
        $guard = new InstallAccessGuard($this->installationState(0), 'secret-token', 'test');

        $guard->ensureOpen();
        self::assertFalse($guard->isUnlocked($this->requestWithSession()));
    }

    public function test_wrong_token_does_not_unlock(): void
    {
        $guard = new InstallAccessGuard($this->installationState(0), 'secret-token', 'test');
        $request = $this->requestWithSession();

        self::assertFalse($guard->unlock($request->getSession(), 'wrong'));
        self::assertFalse($guard->isUnlocked($request));
    }

    public function test_query_string_token_does_not_unlock(): void
    {
        $guard = new InstallAccessGuard($this->installationState(0), 'secret-token', 'test');
        $request = Request::create('/install?token=secret-token');
        $request->setSession(new Session(new MockArraySessionStorage()));

        self::assertFalse($guard->isUnlocked($request));
    }

    public function test_correct_token_unlocks_remembers_and_changes_the_session_id(): void
    {
        $guard = new InstallAccessGuard($this->installationState(0), 'secret-token', 'test');
        $request = $this->requestWithSession();
        $request->getSession()->start();
        $before = $request->getSession()->getId();

        self::assertTrue($guard->unlock($request->getSession(), 'secret-token'));
        self::assertNotSame($before, $request->getSession()->getId());

        // A later step's request reuses the same session and is not re-challenged.
        $later = Request::create('/install/admin');
        $later->setSession($request->getSession());
        self::assertTrue($guard->isUnlocked($later));
    }

    public function test_unlock_never_succeeds_when_no_token_is_configured(): void
    {
        $guard = new InstallAccessGuard($this->installationState(0), '', 'test');

        self::assertFalse($guard->unlock($this->requestWithSession()->getSession(), ''));
    }

    public function test_production_without_a_configured_token_404s_rather_than_opening(): void
    {
        // A forgotten INSTALL_TOKEN in production must not leave an
        // admin-minting endpoint publicly reachable.
        $guard = new InstallAccessGuard($this->installationState(0), '', 'prod');

        $this->expectException(NotFoundHttpException::class);
        $guard->ensureOpen();
    }

    public function test_production_with_a_configured_token_admits_the_correct_token(): void
    {
        $guard = new InstallAccessGuard($this->installationState(0), 'secret-token', 'prod');
        $request = $this->requestWithSession();

        $guard->ensureOpen();
        self::assertTrue($guard->unlock($request->getSession(), 'secret-token'));
        self::assertTrue($guard->isUnlocked($request));
    }
}
