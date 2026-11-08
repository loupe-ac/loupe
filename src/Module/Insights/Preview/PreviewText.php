<?php

declare(strict_types=1);

namespace App\Module\Insights\Preview;

/** SVG text does not wrap, so each text gets a budget of characters. */
final class PreviewText
{
    private const string ELLIPSIS = '…';

    /** Cuts the text to the budget, and ends a cut text with an ellipsis that counts in the budget. */
    public static function fit(string $text, int $budget): string
    {
        $text = trim($text);
        if ($budget < 1) {
            return '';
        }
        if (mb_strlen($text) <= $budget) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $budget - 1)).self::ELLIPSIS;
    }
}
