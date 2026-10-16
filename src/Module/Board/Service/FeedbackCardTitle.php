<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

/** The title of a card that a widget note creates: the start of the note, with an ellipsis when cut. */
final class FeedbackCardTitle
{
    private const int LENGTH = 80;

    public static function of(string $body): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $body) ?? $body);
        if (mb_strlen($text) <= self::LENGTH) {
            return $text;
        }

        $cut = mb_substr($text, 0, self::LENGTH - 1);
        $space = mb_strrpos($cut, ' ');
        if (false !== $space && $space >= (self::LENGTH - 1) / 2) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut).'…';
    }
}
