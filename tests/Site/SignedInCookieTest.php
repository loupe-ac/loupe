<?php

declare(strict_types=1);

namespace App\Tests\Site;

use App\Site\SignedInCookie;
use App\Site\SiteHost;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

final class SignedInCookieTest extends TestCase
{
    private function split(): SignedInCookie
    {
        return new SignedInCookie(new SiteHost('loupe.ac', 'https://app.loupe.ac'));
    }

    public function test_a_split_instance_issues_a_cookie_on_the_site_host(): void
    {
        $cookie = $this->split()->issue(admin: false, persistent: false, secure: true, responseHost: 'app.loupe.ac');

        self::assertInstanceOf(Cookie::class, $cookie);
        self::assertSame('loupe_signed_in', $cookie->getName());
        self::assertSame('1', $cookie->getValue());
        self::assertSame('loupe.ac', $cookie->getDomain());
        self::assertSame('/', $cookie->getPath());
        self::assertTrue($cookie->isSecure());
        self::assertFalse($cookie->isHttpOnly());
        self::assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
        self::assertSame(0, $cookie->getExpiresTime());
    }

    public function test_an_administrator_gets_the_admin_value(): void
    {
        $cookie = $this->split()->issue(admin: true, persistent: false, secure: false, responseHost: 'app.loupe.ac');

        self::assertInstanceOf(Cookie::class, $cookie);
        self::assertSame('admin', $cookie->getValue());
        self::assertFalse($cookie->isSecure());
    }

    public function test_a_remembered_sign_in_lasts_thirty_days(): void
    {
        $cookie = $this->split()->issue(admin: false, persistent: true, secure: true, responseHost: 'app.loupe.ac');

        self::assertInstanceOf(Cookie::class, $cookie);
        self::assertEqualsWithDelta(time() + 2592000, $cookie->getExpiresTime(), 5);
    }

    public function test_the_clearing_cookie_matches_the_issued_one(): void
    {
        $cookie = $this->split()->clear(secure: true, responseHost: 'app.loupe.ac');

        self::assertInstanceOf(Cookie::class, $cookie);
        self::assertSame('loupe_signed_in', $cookie->getName());
        self::assertNull($cookie->getValue());
        self::assertSame('loupe.ac', $cookie->getDomain());
        self::assertSame('/', $cookie->getPath());
        self::assertLessThan(time(), $cookie->getExpiresTime());
    }

    public function test_an_unsplit_instance_sets_and_clears_nothing(): void
    {
        foreach ([new SiteHost('', 'https://app.loupe.ac'), new SiteHost('app.loupe.ac', 'https://app.loupe.ac')] as $siteHost) {
            $cookies = new SignedInCookie($siteHost);

            self::assertNull($cookies->issue(admin: false, persistent: true, secure: true, responseHost: 'app.loupe.ac'));
            self::assertNull($cookies->clear(secure: true, responseHost: 'app.loupe.ac'));
        }
    }

    public function test_a_response_host_outside_the_site_host_gets_no_cookie(): void
    {
        $cookies = $this->split();

        foreach (['example.com', 'notloupe.ac', 'loupe.ac.evil.test'] as $host) {
            self::assertNull($cookies->issue(admin: false, persistent: false, secure: true, responseHost: $host), $host);
            self::assertNull($cookies->clear(secure: true, responseHost: $host), $host);
        }
        self::assertInstanceOf(Cookie::class, $cookies->issue(admin: false, persistent: false, secure: true, responseHost: 'LOUPE.ac'));
    }

    public function test_the_signal_is_read_on_the_site_host_only(): void
    {
        $cookies = $this->split();

        self::assertSame('user', $cookies->signal($this->request('loupe.ac', '1')));
        self::assertSame('admin', $cookies->signal($this->request('loupe.ac', 'admin')));
        self::assertNull($cookies->signal($this->request('loupe.ac', 'yes')));
        self::assertNull($cookies->signal($this->request('loupe.ac', null)));
        self::assertNull($cookies->signal($this->request('app.loupe.ac', 'admin')));
        self::assertNull($cookies->signal(null));
    }

    private function request(string $host, ?string $value): Request
    {
        $request = Request::create('https://'.$host.'/');
        if (null !== $value) {
            $request->cookies->set('loupe_signed_in', $value);
        }

        return $request;
    }
}
