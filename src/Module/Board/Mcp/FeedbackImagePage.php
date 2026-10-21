<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;

/** One page of the screenshots of open notes, with the count that did not fit. */
final readonly class FeedbackImagePage
{
    /**
     * @param list<ImageContent> $images    newest note first
     * @param list<string>       $noteIds   the id of the note of each image, in the same order
     * @param int                $remaining how many open screenshots lie beyond this page
     */
    public function __construct(
        public array $images,
        public array $noteIds,
        public int $remaining,
    ) {
    }

    /**
     * The structured result, then its JSON as text for a client that reads
     * only content, then each image behind a line that names its note.
     *
     * @param array<string, mixed> $structured
     */
    public function resultOf(array $structured): CallToolResult
    {
        $content = [new TextContent(json_encode($structured, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE))];
        foreach ($this->images as $index => $image) {
            $content[] = new TextContent(\sprintf('Screenshot of feedback %s:', $this->noteIds[$index]));
            $content[] = $image;
        }

        return new CallToolResult(
            $content,
            structuredContent: $structured,
        );
    }
}
