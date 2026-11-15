<?php

declare(strict_types=1);

namespace App\Module\Account\Service;

/**
 * Names the state of the profile form. The page renders it at load, and a
 * reconnected page compares it with the current one to learn that the profile
 * changed while the connection was down.
 */
final class ProfileFingerprint
{
    private function __construct()
    {
    }

    public static function of(string $fullName): string
    {
        return substr(hash('sha256', $fullName), 0, 16);
    }
}
