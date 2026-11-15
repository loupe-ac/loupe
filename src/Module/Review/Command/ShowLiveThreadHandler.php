<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\Repository\DocumentVersionRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Reads one thread of the latest version. A comment that is gone, deleted or
 * on an older version answers null, and the page reads the whole collection.
 */
final readonly class ShowLiveThreadHandler
{
    public function __construct(
        private CommentRepository $comments,
        private DocumentVersionRepository $documentVersions,
    ) {
    }

    public function __invoke(ShowLiveThreadCommand $command): ?ShowLiveThreadView
    {
        if (!Uuid::isValid($command->commentId)) {
            return null;
        }

        $comment = $this->comments->find(Uuid::fromString($command->commentId));
        $root = null === $comment ? null : ($comment->parent ?? $comment);
        if (null === $root || $root->isDeleted || $root->version->document !== $command->document) {
            return null;
        }

        $latest = $this->documentVersions->findLatest($command->document);
        if (!$root->version->id?->equals($latest->id)) {
            return null;
        }

        return new ShowLiveThreadView($root, $this->comments->findReplies($root));
    }
}
