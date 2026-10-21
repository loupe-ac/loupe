<?php

declare(strict_types=1);

namespace App\Module\Landing\Service;

use Dom\HTMLDocument;

/**
 * Cuts the rules that one piece of markup needs out of the compiled app
 * stylesheet, so that a shadow root can carry them alone.
 *
 * @phpstan-type Block array{prelude: string, body: ?string}
 * @phpstan-type Node array{selector: string, body: string, children: list<mixed>, vars: array<string, string>}
 */
final class ChromeCssExtractor
{
    private const string LAYER_ORDER = '@layer properties, theme, base, components, utilities;';

    private const array ROOT_SELECTORS = [':root', ':host', 'html'];

    private const array NESTING_AT_RULES = ['layer', 'media', 'supports', 'container'];

    public function extract(string $css, string $html): string
    {
        $classes = $this->classesOf($html);
        /** @var array<string, string> $initialValues */
        $initialValues = [];
        $nodes = $this->filter($this->blocks($css), $classes, false, $initialValues);

        $text = $this->render($nodes, false);
        $kept = $this->includedVariables($nodes, $text);
        $text = $this->render($nodes, true, $kept);

        $used = $this->referencedVariables($text);
        $properties = '';
        foreach ($initialValues as $name => $value) {
            if (isset($used[$name])) {
                $properties .= $name.': '.$value.'; ';
            }
        }
        if ('' !== $properties) {
            $text = '@layer properties { *, ::before, ::after, ::backdrop { '.trim($properties).' } }'."\n".$text;
        }

        return self::LAYER_ORDER."\n".$text;
    }

    /** @return array<string, true> */
    private function classesOf(string $html): array
    {
        $classes = [];
        $document = HTMLDocument::createFromString('<body>'.$html.'</body>', \LIBXML_NOERROR);
        foreach ($document->querySelectorAll('[class]') as $element) {
            foreach (preg_split('/\s+/', trim($element->getAttribute('class') ?? '')) ?: [] as $class) {
                if ('' !== $class) {
                    $classes[$class] = true;
                }
            }
        }

        return $classes;
    }

    /** @return list<Block> */
    private function blocks(string $css): array
    {
        $blocks = [];
        $length = \strlen($css);
        $position = 0;
        while ($position < $length) {
            $position = $this->skipSpace($css, $position);
            if ($position >= $length) {
                break;
            }
            $start = $position;
            $end = $this->scanTo($css, $position, ['{', ';', '}']);
            $prelude = trim(substr($css, $start, $end - $start));
            if ($end >= $length || '}' === $css[$end]) {
                break;
            }
            if (';' === $css[$end]) {
                $blocks[] = ['prelude' => $prelude, 'body' => null];
                $position = $end + 1;
                continue;
            }
            $close = $this->matchingBrace($css, $end);
            $blocks[] = ['prelude' => $prelude, 'body' => substr($css, $end + 1, $close - $end - 1)];
            $position = $close + 1;
        }

        return $blocks;
    }

    private function skipSpace(string $css, int $position): int
    {
        $length = \strlen($css);
        while ($position < $length) {
            if (ctype_space($css[$position])) {
                ++$position;
            } elseif ('/*' === substr($css, $position, 2)) {
                $end = strpos($css, '*/', $position + 2);
                $position = false === $end ? $length : $end + 2;
            } else {
                break;
            }
        }

        return $position;
    }

    /**
     * The first offset at depth zero that holds one of the characters.
     *
     * @param list<string> $stops
     */
    private function scanTo(string $css, int $position, array $stops): int
    {
        $length = \strlen($css);
        $depth = 0;
        while ($position < $length) {
            $char = $css[$position];
            if ('\\' === $char) {
                $position += 2;
                continue;
            }
            if ('/*' === substr($css, $position, 2)) {
                $end = strpos($css, '*/', $position + 2);
                $position = false === $end ? $length : $end + 2;
                continue;
            }
            if ('"' === $char || "'" === $char) {
                $position = $this->skipString($css, $position);
                continue;
            }
            if ('(' === $char || '[' === $char) {
                ++$depth;
            } elseif (')' === $char || ']' === $char) {
                --$depth;
            } elseif (0 >= $depth && \in_array($char, $stops, true)) {
                return $position;
            }
            ++$position;
        }

        return $length;
    }

    private function skipString(string $css, int $position): int
    {
        $quote = $css[$position];
        $length = \strlen($css);
        ++$position;
        while ($position < $length && $css[$position] !== $quote) {
            $position += '\\' === $css[$position] ? 2 : 1;
        }

        return $position + 1;
    }

    private function matchingBrace(string $css, int $open): int
    {
        $length = \strlen($css);
        $depth = 0;
        for ($position = $open; $position < $length; ++$position) {
            $char = $css[$position];
            if ('\\' === $char) {
                ++$position;
            } elseif ('"' === $char || "'" === $char) {
                $position = $this->skipString($css, $position) - 1;
            } elseif ('/*' === substr($css, $position, 2)) {
                $end = strpos($css, '*/', $position + 2);
                $position = false === $end ? $length : $end + 1;
            } elseif ('{' === $char) {
                ++$depth;
            } elseif ('}' === $char && 0 === --$depth) {
                return $position;
            }
        }

        return $length;
    }

    /**
     * @param list<Block>           $blocks
     * @param array<string, true>   $classes
     * @param array<string, string> $initialValues
     *
     * @return list<Node>
     */
    private function filter(array $blocks, array $classes, bool $inBase, array &$initialValues): array
    {
        $nodes = [];
        foreach ($blocks as $block) {
            if (str_starts_with($block['prelude'], '@')) {
                $node = $this->filterAtRule($block, $classes, $inBase, $initialValues);
            } elseif (null !== $block['body']) {
                $node = $this->filterRule($block['prelude'], $block['body'], $classes, $inBase);
            } else {
                $node = null;
            }
            if (null !== $node) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * @param Block                 $block
     * @param array<string, true>   $classes
     * @param array<string, string> $initialValues
     *
     * @return Node|null
     */
    private function filterAtRule(array $block, array $classes, bool $inBase, array &$initialValues): ?array
    {
        if (null === $block['body'] || 1 !== preg_match('/^@([\w-]+)\s*(.*)$/s', $block['prelude'], $match)) {
            return null;
        }
        [, $name, $rest] = $match;
        if ('property' === $name) {
            if (1 === preg_match('/initial-value:\s*([^;]+)/', $block['body'], $initial)) {
                $initialValues[trim($rest)] = trim($initial[1]);
            }

            return null;
        }
        if (!\in_array($name, self::NESTING_AT_RULES, true)) {
            return null;
        }
        $children = $this->filter(
            $this->blocks($block['body']),
            $classes,
            $inBase || ('layer' === $name && 'base' === trim($rest)),
            $initialValues,
        );

        return [] === $children ? null : ['selector' => $block['prelude'], 'body' => '', 'children' => $children, 'vars' => []];
    }

    /**
     * @param array<string, true> $classes
     *
     * @return Node|null
     */
    private function filterRule(string $selectorList, string $body, array $classes, bool $inBase): ?array
    {
        $selectors = array_map(trim(...), $this->splitTopLevel($selectorList));
        $declarations = array_values(array_filter(array_map(trim(...), $this->splitTopLevel($body, ';')), static fn (string $d): bool => '' !== $d));

        if ([] !== $declarations && [] === array_filter($declarations, static fn (string $d): bool => !str_starts_with($d, '--'))) {
            if ([] === array_diff($selectors, self::ROOT_SELECTORS)) {
                $vars = [];
                foreach ($declarations as $declaration) {
                    [$name, $value] = explode(':', $declaration, 2) + [1 => ''];
                    $vars[trim($name)] = trim($value);
                }

                return ['selector' => ':host', 'body' => '', 'children' => [], 'vars' => $vars];
            }
        }

        $kept = array_values(array_filter($selectors, fn (string $selector): bool => $this->selectorIsNeeded($selector, $classes, $inBase)));
        if ([] === $kept) {
            return null;
        }

        return ['selector' => implode(', ', $kept), 'body' => $this->compact($body), 'children' => [], 'vars' => []];
    }

    /** @param array<string, true> $classes */
    private function selectorIsNeeded(string $selector, array $classes, bool $inBase): bool
    {
        preg_match_all('/\.((?:\\\\.|[\w-])+)/', $selector, $matches);
        if ([] === $matches[1]) {
            return $inBase;
        }

        return array_all($matches[1], fn ($class) => isset($classes[(string) preg_replace('/\\\\(.)/', '$1', $class)]));
    }

    /** @return list<string> */
    private function splitTopLevel(string $text, string $separator = ','): array
    {
        $parts = [];
        $start = 0;
        $length = \strlen($text);
        while ($start <= $length) {
            $end = $this->scanTo($text, $start, [$separator]);
            $parts[] = substr($text, $start, $end - $start);
            $start = $end + 1;
        }

        return $parts;
    }

    private function compact(string $body): string
    {
        return trim((string) preg_replace('/\s*\n\s*/', ' ', (string) preg_replace('~/\*.*?\*/~s', '', $body)));
    }

    /**
     * @param list<Node> $nodes
     *
     * @return array<string, true> the custom properties to keep
     */
    private function includedVariables(array $nodes, string $text): array
    {
        $declared = [];
        $this->collectVariables($nodes, $declared);
        $included = [];
        $queue = array_keys($this->referencedVariables($text));
        while ([] !== $queue) {
            $name = array_pop($queue);
            if (isset($included[$name]) || !isset($declared[$name])) {
                continue;
            }
            $included[$name] = true;
            array_push($queue, ...array_keys($this->referencedVariables($declared[$name])));
        }

        return $included;
    }

    /**
     * @param list<Node>            $nodes
     * @param array<string, string> $declared
     */
    private function collectVariables(array $nodes, array &$declared): void
    {
        foreach ($nodes as $node) {
            foreach ($node['vars'] as $name => $value) {
                $declared[$name] = $value;
            }
            /** @var list<Node> $children */
            $children = $node['children'];
            $this->collectVariables($children, $declared);
        }
    }

    /** @return array<string, true> */
    private function referencedVariables(string $text): array
    {
        preg_match_all('/var\(\s*(--[\w-]+)/', $text, $matches);

        return array_fill_keys($matches[1], true);
    }

    /**
     * @param list<Node>          $nodes
     * @param array<string, true> $included
     */
    private function render(array $nodes, bool $withVariables, array $included = []): string
    {
        $out = [];
        foreach ($nodes as $node) {
            if ([] !== $node['vars']) {
                $declarations = [];
                foreach ($node['vars'] as $name => $value) {
                    if ($withVariables && isset($included[$name])) {
                        $declarations[] = $name.': '.$this->compact($value).';';
                    }
                }
                if ([] !== $declarations) {
                    $out[] = $node['selector'].' { '.implode(' ', $declarations).' }';
                }
            } elseif ([] !== $node['children']) {
                /** @var list<Node> $children */
                $children = $node['children'];
                $inner = $this->render($children, $withVariables, $included);
                if ('' !== $inner) {
                    $out[] = $node['selector'].' {'."\n".$inner."\n".'}';
                }
            } else {
                $out[] = $node['selector'].' { '.$node['body'].' }';
            }
        }

        return implode("\n", $out);
    }
}
