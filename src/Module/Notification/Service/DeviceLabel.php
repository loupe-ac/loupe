<?php

declare(strict_types=1);

namespace App\Module\Notification\Service;

use App\Module\Notification\Entity\PushDevice;

/** The name of a device in the list. The user types one, or Loupe reads it off the user agent. */
final readonly class DeviceLabel
{
    private const string FALLBACK = 'Browser';

    /** Trims the text and cuts it to the column length. Blank text gives null. */
    public static function normalize(?string $label): ?string
    {
        $label = null === $label ? '' : mb_substr(trim($label), 0, PushDevice::LABEL_MAX_LENGTH);
        $label = trim($label);

        return '' === $label ? null : $label;
    }

    public static function fromUserAgent(?string $userAgent): string
    {
        $userAgent ??= '';
        $browser = self::browser($userAgent);
        if (null === $browser) {
            return self::FALLBACK;
        }

        $system = self::system($userAgent);

        return null === $system ? $browser : $browser.' on '.$system;
    }

    private static function browser(string $userAgent): ?string
    {
        return match (true) {
            str_contains($userAgent, 'Edg/'), str_contains($userAgent, 'EdgA/'), str_contains($userAgent, 'EdgiOS/') => 'Edge',
            str_contains($userAgent, 'OPR/') => 'Opera',
            str_contains($userAgent, 'Firefox/'), str_contains($userAgent, 'FxiOS/') => 'Firefox',
            str_contains($userAgent, 'Chrome/'), str_contains($userAgent, 'CriOS/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => null,
        };
    }

    private static function system(string $userAgent): ?string
    {
        return match (true) {
            str_contains($userAgent, 'iPhone') => 'iOS',
            str_contains($userAgent, 'iPad') => 'iPadOS',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'CrOS') => 'ChromeOS',
            str_contains($userAgent, 'Macintosh'), str_contains($userAgent, 'Mac OS X') => 'macOS',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };
    }
}
