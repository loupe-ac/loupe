<?php

declare(strict_types=1);

namespace App\Module\OAuth\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class PreviewSignInExtension extends AbstractExtension
{
    #[\Override]
    public function getFilters(): array
    {
        return [
            new TwigFilter('preview_token_days_left', self::daysLeft(...)),
        ];
    }

    /** Whole days left, so a token with less than one day left reads 0. */
    public static function daysLeft(\DateTimeImmutable $expiresAt, ?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable();

        return intdiv(max(0, $expiresAt->getTimestamp() - $now->getTimestamp()), 86400);
    }
}
