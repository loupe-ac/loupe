<?php

declare(strict_types=1);

namespace App\Module\Board\View;

/**
 * The moment a list page rendered, as the page sends it back on a live read.
 * Microseconds keep a card made in the same second as the cursor on the right side of it.
 */
final readonly class LiveCursor
{
    public const string FORMAT = 'Y-m-d\TH:i:s.uP';

    public static function format(\DateTimeImmutable $moment): string
    {
        return $moment->format(self::FORMAT);
    }

    /** Null for a missing or malformed value, so a hand-edited URL reads as no cursor. */
    public static function parse(?string $value): ?\DateTimeImmutable
    {
        if (null === $value || str_contains($value, "\0")) {
            return null;
        }
        $moment = \DateTimeImmutable::createFromFormat(self::FORMAT, $value);
        if (false === $moment || $moment->format(self::FORMAT) !== $value) {
            return null;
        }

        return $moment;
    }
}
