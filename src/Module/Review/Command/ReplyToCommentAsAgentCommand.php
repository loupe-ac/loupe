<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;

/**
 * An agent's reply into a document-review thread.
 *
 * It carries no actor. The author is the singleton agent user, and the handler
 * looks it up: an MCP request authenticates as the project owner, so letting a
 * caller name the author would show the reviewer saying things they never wrote.
 *
 * A caller that reaches the comment through a document passes it, and the
 * handler refuses a comment of another document. The MCP tool names no
 * document and passes null.
 */
final readonly class ReplyToCommentAsAgentCommand
{
    public function __construct(
        public Comment $parent,
        public string $body,
        public ?Document $document = null,
    ) {
    }
}
