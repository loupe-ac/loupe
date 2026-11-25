<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

/** Puts outside text into the Markdown body of an item that Loupe writes. */
final readonly class MarkdownLiteral
{
    /** The text as one line that the renderer shows as written. A line break could end its block. */
    public static function line(string $text): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', $text) ?? throw new \LogicException('A stored text is valid UTF-8.');

        // Markdown lets a backslash escape any ASCII punctuation, so no character keeps a meaning.
        return preg_replace('/[!-\/:-@\[-`{-~]/', '\\\\$0', $collapsed) ?? throw new \LogicException('The pattern is valid.');
    }
}
