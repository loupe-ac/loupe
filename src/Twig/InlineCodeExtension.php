<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFilter;

final class InlineCodeExtension extends AbstractExtension
{
    #[\Override]
    public function getFilters(): array
    {
        return [
            new TwigFilter('inline_code', $this->inlineCode(...)),
        ];
    }

    /** Escapes the text, then turns each pair of backticks into a code element. */
    public function inlineCode(string $text): Markup
    {
        $escaped = htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        return new Markup(preg_replace('/`([^`]+)`/', '<code>$1</code>', $escaped) ?? $escaped, 'UTF-8');
    }
}
