<?php

declare(strict_types=1);

namespace App\Tests\Module\Notification\Service;

use App\Module\Notification\Service\DeviceCookie;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

final class DeviceCookieTest extends TestCase
{
    public function test_a_generated_token_is_long_random_and_url_safe(): void
    {
        $cookies = new DeviceCookie();

        $first = $cookies->generate();

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $first);
        self::assertNotSame($first, $cookies->generate());
    }

    public function test_the_hash_is_the_sha256_hex_of_the_token(): void
    {
        self::assertSame(hash('sha256', 'abc'), DeviceCookie::hash('abc'));
    }

    public function test_it_reads_the_token_and_its_hash_from_the_request(): void
    {
        $cookies = new DeviceCookie();
        $token = $cookies->generate();
        $request = new Request(cookies: [DeviceCookie::NAME => $token]);

        self::assertSame($token, $cookies->tokenFrom($request));
        self::assertSame(hash('sha256', $token), $cookies->currentHash($request));
    }

    public function test_a_missing_or_malformed_cookie_gives_no_token(): void
    {
        $cookies = new DeviceCookie();

        self::assertNull($cookies->tokenFrom(new Request()));
        self::assertNull($cookies->currentHash(new Request()));
        self::assertNull($cookies->tokenFrom(new Request(cookies: [DeviceCookie::NAME => 'short'])));
        self::assertNull($cookies->tokenFrom(new Request(cookies: [DeviceCookie::NAME => str_repeat('a', 43).'!'])));
        self::assertNull($cookies->tokenFrom(new Request(cookies: [DeviceCookie::NAME => ['x']])));
    }

    public function test_the_cookie_is_http_only_lax_and_lives_400_days(): void
    {
        $now = time();

        $cookie = new DeviceCookie()->cookie('token-value', true);

        self::assertSame('loupe_push_device', $cookie->getName());
        self::assertSame('token-value', $cookie->getValue());
        self::assertTrue($cookie->isHttpOnly());
        self::assertTrue($cookie->isSecure());
        self::assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
        self::assertSame('/', $cookie->getPath());
        self::assertEqualsWithDelta($now + 400 * 86400, $cookie->getExpiresTime(), 5);
    }

    public function test_the_expiring_cookie_is_in_the_past_and_empty(): void
    {
        $cookie = new DeviceCookie()->expire(true);

        self::assertSame('loupe_push_device', $cookie->getName());
        self::assertNull($cookie->getValue());
        self::assertLessThan(time(), $cookie->getExpiresTime());
    }
}
