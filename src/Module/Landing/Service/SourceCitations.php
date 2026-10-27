<?php

declare(strict_types=1);

namespace App\Module\Landing\Service;

use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Node\StringContainerHelper;

/**
 * Turns each `[n]` citation of a comparison page into a link to item n of the
 * ordered "Sources" list, and each `[Ln]` citation into a link to the bullet
 * item that opens `Ln.`. Each item gets the id `source-<key>` that the link targets.
 * Turbo swallows a click on a same-page anchor, so the link opts out of Turbo.
 */
final class SourceCitations
{
    public const string HEADING = 'Sources';

    private const string CITATION = '/\[(L?\d+)\]/';

    private const string KEYED_ITEM = '/^(L\d+)\./';

    public function __invoke(DocumentParsedEvent $event): void
    {
        $document = $event->getDocument();
        $keys = $this->markSources($document);

        $texts = [];
        foreach ($document->iterator() as $node) {
            if ($node instanceof Text && !$this->inside($node, Link::class) && !$this->inside($node, Code::class) && !$this->inside($node, Image::class)) {
                $texts[] = $node;
            }
        }
        foreach ($texts as $text) {
            $this->linkCitations($text, $keys);
        }
    }

    /** @return list<string> the keys of the listed sources */
    private function markSources(Node $document): array
    {
        $keys = [];
        foreach ($document->children() as $node) {
            if (!$node instanceof Heading || self::HEADING !== trim(StringContainerHelper::getChildText($node))) {
                continue;
            }
            for ($next = $node->next(); null !== $next && !$next instanceof Heading; $next = $next->next()) {
                if (!$next instanceof ListBlock) {
                    continue;
                }
                $number = $next->getListData()->start ?? 1;
                foreach ($next->children() as $item) {
                    if (!$item instanceof ListItem) {
                        continue;
                    }
                    if (ListBlock::TYPE_ORDERED === $next->getListData()->type) {
                        $key = (string) $number++;
                    } elseif (1 === preg_match(self::KEYED_ITEM, trim(StringContainerHelper::getChildText($item)), $match)) {
                        $key = $match[1];
                    } else {
                        continue;
                    }
                    $item->data->set('attributes/id', 'source-'.$key);
                    $keys[] = $key;
                }
            }
        }

        return $keys;
    }

    /** @param list<string> $keys */
    private function linkCitations(Text $text, array $keys): void
    {
        $parts = preg_split(self::CITATION, $text->getLiteral(), -1, \PREG_SPLIT_DELIM_CAPTURE);
        if (false === $parts || 1 === \count($parts)) {
            return;
        }

        foreach ($parts as $index => $part) {
            if (0 === $index % 2) {
                if ('' !== $part) {
                    $text->insertBefore(new Text($part));
                }
                continue;
            }
            if (!\in_array($part, $keys, true)) {
                throw new \UnexpectedValueException(sprintf('cites [%s], which is not in its %s list', $part, self::HEADING));
            }
            $link = new Link('#source-'.$part, '['.$part.']');
            $link->data->set('attributes/data-turbo', 'false');
            $text->insertBefore($link);
        }
        $text->detach();
    }

    /** @param class-string<Node> $class */
    private function inside(Node $node, string $class): bool
    {
        for ($parent = $node->parent(); null !== $parent; $parent = $parent->parent()) {
            if ($parent instanceof $class) {
                return true;
            }
        }

        return false;
    }
}
