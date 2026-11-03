<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

/**
 * Thrown when a reply names a document and the comment belongs to another one.
 * A caller that addresses a comment through a document must not reach a
 * comment of a different document.
 */
final class CommentNotInDocument extends \DomainException
{
    public function __construct()
    {
        parent::__construct('The comment does not belong to the document.');
    }
}
