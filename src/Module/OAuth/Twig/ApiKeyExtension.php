<?php

declare(strict_types=1);

namespace App\Module\OAuth\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/** Whole-day distances for the expiry column of the API keys page. */
final class ApiKeyExtension extends AbstractExtension
{
    #[\Override]
    public function getFilters(): array
    {
        return [
            new TwigFilter('api_key_days_left', self::daysLeft(...)),
            new TwigFilter('api_key_days_ago', self::daysAgo(...)),
        ];
    }

    /** Days until $expiresAt, rounded up so a new 90-day key reads 90. Less than one day reads 0. */
    public static function daysLeft(\DateTimeImmutable $expiresAt, \DateTimeImmutable $now): int
    {
        $seconds = $expiresAt->getTimestamp() - $now->getTimestamp();

        return $seconds < 86400 ? 0 : intdiv($seconds + 86399, 86400);
    }

    /** Whole days since $endedAt. Less than one day reads 0. */
    public static function daysAgo(\DateTimeImmutable $endedAt, \DateTimeImmutable $now): int
    {
        return max(0, intdiv($now->getTimestamp() - $endedAt->getTimestamp(), 86400));
    }
}
