<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

/**
 * The reference tokens of Markdown: `card:<number>` and `pr:<path>#<number>`.
 *
 * The pass changes the text of a token to the text a reader sees and marks the
 * result with a data attribute. A stored document holds that text, so its
 * comment anchors measure against it. A page-time linker adds the link later.
 */
final readonly class ReferenceTokens
{
    private const string BEFORE = '(?<![\p{L}\p{N}\[:/#])';
    private const string AFTER = '(?![\p{L}\p{N}\]])';
    private const string NUMBER = '[1-9][0-9]{0,8}';
    private const string SEGMENT = '[A-Za-z0-9_.-]+';
    private const array SKIPPED = ['code', 'pre', 'a', 'script', 'style', 'textarea'];

    /** Turns each token of a plain string into its shown text. */
    public function plain(string $text): string
    {
        return $this->replace($text, static fn (string $shown): string => $shown);
    }

    /** Marks each token in the text of sanitized HTML, and leaves code, links and scripts as written. */
    public function mark(string $html): string
    {
        $tokens = preg_split('~(<[^>]*>)~', $html, -1, PREG_SPLIT_DELIM_CAPTURE)
            ?: throw new \RuntimeException('Reference tokenizing failed: '.preg_last_error_msg().'.');

        $skipDepth = 0;
        foreach ($tokens as $i => $token) {
            if (1 === $i % 2) {
                if (1 === preg_match('~^<(/?)([a-zA-Z][a-zA-Z0-9-]*)~', $token, $tag) && in_array(strtolower($tag[2]), self::SKIPPED, true)) {
                    $skipDepth = max(0, $skipDepth + ('/' === $tag[1] ? -1 : 1));
                }
            } elseif (0 === $skipDepth) {
                $tokens[$i] = $this->replace(
                    $token,
                    static fn (string $shown, string $attribute, string $value): string => sprintf('<span %s="%s">%s</span>', $attribute, $value, $shown),
                );
            }
        }

        return implode('', $tokens);
    }

    /** @param callable(string, string, string): string $render receives the shown text, the data attribute and its value */
    private function replace(string $text, callable $render): string
    {
        $pattern = '~'.self::BEFORE.'(?:card:(?<card>'.self::NUMBER.')|pr:(?<path>'.self::SEGMENT.'(?:/'.self::SEGMENT.')+)#(?<number>'.self::NUMBER.'))'.self::AFTER.'~u';

        return preg_replace_callback(
            $pattern,
            /** @param array<array-key, string> $match */
            static function (array $match) use ($render): string {
                if (isset($match['path']) && '' !== $match['path']) {
                    if ([] !== array_intersect(explode('/', $match['path']), ['.', '..'])) {
                        return $match[0];
                    }
                    $shown = $match['path'].'#'.$match['number'];

                    return $render($shown, 'data-ref-pr', $shown);
                }

                return $render('card #'.$match['card'], 'data-ref-card', $match['card']);
            },
            $text,
        ) ?? throw new \RuntimeException('Reference token pass failed: '.preg_last_error_msg().'.');
    }
}
