<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

/** The 12-character code in the address of a public link. */
final readonly class PublicLinkCode
{
    public const string PATTERN = '[a-z2-7]{12}';

    public const int LENGTH = 12;

    private const string ALPHABET = 'abcdefghijklmnopqrstuvwxyz234567';

    public static function generate(): string
    {
        $code = '';
        for ($i = 0; $i < self::LENGTH; ++$i) {
            $code .= self::ALPHABET[random_int(0, \strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }
}
