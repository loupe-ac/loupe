<?php

declare(strict_types=1);

namespace App\Module\OAuth\Entity;

/** How long a new API key lives. */
enum ApiKeyExpiry: string
{
    case ThirtyDays = 'thirty-days';
    case NinetyDays = 'ninety-days';
    case OneYear = 'one-year';
    case Never = 'never';

    public function expiresAt(\DateTimeImmutable $now): ?\DateTimeImmutable
    {
        return match ($this) {
            self::ThirtyDays => $now->modify('+30 days'),
            self::NinetyDays => $now->modify('+90 days'),
            self::OneYear => $now->modify('+365 days'),
            self::Never => null,
        };
    }
}
