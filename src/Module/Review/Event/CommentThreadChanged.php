<?php

declare(strict_types=1);

namespace App\Module\Review\Event;

use Symfony\Component\Uid\Uuid;

/** A comment thread of a document changed. Dispatched after the commit, with ids only. */
final readonly class CommentThreadChanged
{
    public function __construct(
        public Uuid $projectId,
        public Uuid $documentId,
        public Uuid $commentId,
        public CommentThreadScope $scope,
    ) {
    }
}
