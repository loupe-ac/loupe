<?php

declare(strict_types=1);

namespace App\DesignStarter;

/**
 * Cuts a minified stylesheet into parts at rule ends, so a cut never lands
 * inside a rule. A block at-rule over the limit, such as the one `@layer` that
 * Tailwind wraps its output in, is cut at the rule ends inside it and each
 * piece gets the same at-rule head. Pieces of one `@layer` merge in the browser.
 */
final class StylesheetSplitter
{
    /** @return list<string> */
    public function split(string $css, int $limit): array
    {
        $parts = [];
        $current = '';
        foreach ($this->topLevelRules($css) as $rule) {
            if (\strlen($rule) > $limit) {
                foreach ($this->splitBlock($rule, $limit) as $piece) {
                    if ('' !== $current) {
                        $parts[] = $current;
                        $current = '';
                    }
                    $parts[] = $piece;
                }

                continue;
            }

            if ('' !== $current && \strlen($current) + \strlen($rule) > $limit) {
                $parts[] = $current;
                $current = '';
            }
            $current .= $rule;
        }

        if ('' !== $current) {
            $parts[] = $current;
        }

        return $parts;
    }

    /** @return list<string> */
    private function splitBlock(string $rule, int $limit): array
    {
        $open = strpos($rule, '{');
        $head = false === $open ? '' : substr($rule, 0, $open + 1);
        if ('' === $head || '@' !== $head[0] || !str_ends_with($rule, '}')) {
            throw new \RuntimeException(sprintf('One rule is %d bytes, over the limit of %d, and cannot be cut.', \strlen($rule), $limit));
        }

        $body = substr($rule, $open + 1, -1);
        $pieces = $this->split($body, $limit - \strlen($head) - 1);

        return array_map(static fn (string $piece): string => $head.$piece.'}', $pieces);
    }

    /** @return \Generator<string> */
    private function topLevelRules(string $css): \Generator
    {
        $length = \strlen($css);
        $depth = 0;
        $start = 0;
        $quote = null;

        for ($i = 0; $i < $length; ++$i) {
            $char = $css[$i];

            if (null !== $quote) {
                if ('\\' === $char) {
                    ++$i;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ('/' === $char && '*' === ($css[$i + 1] ?? '')) {
                $end = strpos($css, '*/', $i + 2);
                $i = false === $end ? $length : $end + 1;

                continue;
            }

            if ('"' === $char || "'" === $char) {
                $quote = $char;
            } elseif ('\\' === $char) {
                ++$i;
            } elseif ('{' === $char) {
                ++$depth;
            } elseif ('}' === $char) {
                --$depth;
                if (0 === $depth) {
                    yield substr($css, $start, $i + 1 - $start);
                    $start = $i + 1;
                }
            } elseif (';' === $char && 0 === $depth) {
                yield substr($css, $start, $i + 1 - $start);
                $start = $i + 1;
            }
        }

        if ($start < $length) {
            yield substr($css, $start);
        }
    }
}
