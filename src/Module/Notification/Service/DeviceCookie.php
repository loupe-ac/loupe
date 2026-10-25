<?php

declare(strict_types=1);

namespace App\Module\Notification\Service;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tells Loupe which push device row belongs to this browser. The cookie holds a
 * random token and the row holds its hash, so a database leak names no browser.
 */
final readonly class DeviceCookie
{
    public const string NAME = 'loupe_push_device';

    private const int LIFETIME = 400 * 86400;

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** The token a request carries, or null when the cookie is missing or not one that Loupe issued. */
    public function tokenFrom(Request $request): ?string
    {
        $value = $request->cookies->all()[self::NAME] ?? null;

        return \is_string($value) && 1 === preg_match('/^[A-Za-z0-9_-]{43}$/', $value) ? $value : null;
    }

    public function currentHash(Request $request): ?string
    {
        $token = $this->tokenFrom($request);

        return null === $token ? null : self::hash($token);
    }

    public function cookie(string $token, bool $secure): Cookie
    {
        return new Cookie(self::NAME, $token, time() + self::LIFETIME, '/', null, $secure, true, false, Cookie::SAMESITE_LAX);
    }

    public function expire(bool $secure): Cookie
    {
        return new Cookie(self::NAME, null, 1, '/', null, $secure, true, false, Cookie::SAMESITE_LAX);
    }
}
