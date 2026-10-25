<?php

declare(strict_types=1);

namespace App\Module\Notification\Service;

/**
 * Decides which push addresses Loupe sends to. The browser hands Loupe the
 * address, so a forged one must never make the worker call an inner network.
 */
final readonly class PushEndpointPolicy
{
    private const array HOST_SUFFIXES = [
        '.push.apple.com',
        '.push.services.mozilla.com',
        '.notify.windows.com',
    ];

    private const array HOSTS = [
        'fcm.googleapis.com',
    ];

    public function accepts(string $endpoint): bool
    {
        if (1 === preg_match('/[\x00-\x20\x7f\\\\]/', $endpoint)) {
            return false;
        }

        $parts = parse_url($endpoint);
        if (false === $parts || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if ('https' !== strtolower($parts['scheme']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        if (isset($parts['port']) && 443 !== $parts['port']) {
            return false;
        }

        $host = strtolower($parts['host']);
        if (1 !== preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $host)) {
            return false;
        }

        if (\in_array($host, self::HOSTS, true)) {
            return true;
        }

        return array_any(self::HOST_SUFFIXES, static fn (string $suffix): bool => str_ends_with($host, $suffix));
    }
}
