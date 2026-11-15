<?php

declare(strict_types=1);

namespace App\Module\Review\Event;

/** How much of a review page a comment change touches. */
enum CommentThreadScope: string
{
    /** One existing thread changed, and no count did. */
    case Thread = 'thread';

    /** A thread appeared or went, or a status count changed. */
    case Collection = 'collection';
}
