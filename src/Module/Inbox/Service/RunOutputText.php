<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

/** Cleans the output of a worker run for a person. Old runs and other harnesses still write the stage protocol. */
final class RunOutputText
{
    public static function clean(string $output): string
    {
        $text = trim($output);
        $text = (string) preg_replace('/\A\s*STAGE RESULT:\s*(?:blocked:\s*)?/i', '', $text);
        $text = (string) preg_replace('/\s*\[reason:\s*[a-z0-9-]+\]/i', '', $text);

        return trim($text);
    }

    /** The first sentence of the cleaned output, cut to a length a list row can hold. */
    public static function headline(string $output, int $maxLength = 120): string
    {
        $text = self::clean($output);
        $parts = preg_split('/(?<=[.!?])\s|\R/u', $text, 2);
        $first = self::plain(false === $parts ? $text : $parts[0]);
        $first = rtrim($first, '.');

        return mb_strlen($first) > $maxLength ? rtrim(mb_substr($first, 0, $maxLength - 1)).'…' : $first;
    }

    /** A worker writes Markdown, and a title and a body sentence show plain text, so the marks go and their text stays. */
    private static function plain(string $text): string
    {
        $text = (string) preg_replace('/^\s*(?:#{1,6}\s+|>\s*|[-*+]\s+|\d+[.)]\s+)/u', '', $text);
        $text = (string) preg_replace('/!?\[([^\]]*)\]\([^)]*\)/u', '$1', $text);
        $text = (string) preg_replace('/`+([^`]+)`+/u', '$1', $text);
        $text = (string) preg_replace('/(\*\*|__)(\S(?:.*?\S)?)\1/u', '$2', $text);
        $text = (string) preg_replace('/(?<![\w*])\*(\S(?:.*?\S)?)\*(?![\w*])/u', '$1', $text);
        $text = (string) preg_replace('/(?<!\w)_(\S(?:.*?\S)?)_(?!\w)/u', '$1', $text);

        return trim($text);
    }
}
