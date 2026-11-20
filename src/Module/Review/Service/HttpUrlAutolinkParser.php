<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use League\CommonMark\Extension\Autolink\UrlAutolinkParser;
use League\CommonMark\Parser\Inline\InlineParserInterface;
use League\CommonMark\Parser\Inline\InlineParserMatch;
use League\CommonMark\Parser\InlineParserContext;

/**
 * Links a bare address that starts with `http://` or `https://`. The library
 * parser always starts on `www.` as well, so this wrapper limits the start
 * prefixes and leaves a bare `www.` address as text.
 */
final readonly class HttpUrlAutolinkParser implements InlineParserInterface
{
    private UrlAutolinkParser $inner;

    public function __construct()
    {
        $this->inner = new UrlAutolinkParser(['https', 'http']);
    }

    #[\Override]
    public function getMatchDefinition(): InlineParserMatch
    {
        return InlineParserMatch::oneOf('https://', 'http://');
    }

    #[\Override]
    public function parse(InlineParserContext $inlineContext): bool
    {
        return $this->inner->parse($inlineContext);
    }
}
