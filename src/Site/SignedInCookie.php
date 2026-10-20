<?php

declare(strict_types=1);

namespace App\Site;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * The cookie that tells the site host that this browser is signed in on the app
 * host. It holds `1` or `admin` and no identity. A split instance alone uses it,
 * because on one host the session itself answers the question.
 */
final readonly class SignedInCookie
{
    public const string NAME = 'loupe_signed_in';

    public const string USER = 'user';

    public const string ADMIN = 'admin';

    /** The lifetime of the remember-me cookie in config/packages/security.yaml. */
    private const int REMEMBER_ME_LIFETIME = 2592000;

    public function __construct(
        private SiteHost $siteHost,
    ) {
    }

    public function issue(bool $admin, bool $persistent, bool $secure): ?Cookie
    {
        if (!$this->siteHost->isSplit()) {
            return null;
        }

        return $this->cookie($admin ? 'admin' : '1', $persistent ? time() + self::REMEMBER_ME_LIFETIME : 0, $secure);
    }

    public function clear(bool $secure): ?Cookie
    {
        if (!$this->siteHost->isSplit()) {
            return null;
        }

        return $this->cookie(null, 1, $secure);
    }

    /** Null on the app host: the cookie also reaches it, and the session answers there. */
    public function signal(?Request $request): ?string
    {
        if (null === $request || !$this->siteHost->isSiteRequest($request->getHost())) {
            return null;
        }

        return match ($request->cookies->get(self::NAME)) {
            '1' => self::USER,
            'admin' => self::ADMIN,
            default => null,
        };
    }

    private function cookie(?string $value, int $expires, bool $secure): Cookie
    {
        return new Cookie(self::NAME, $value, $expires, '/', $this->siteHost->host, $secure, false, false, Cookie::SAMESITE_LAX);
    }
}
