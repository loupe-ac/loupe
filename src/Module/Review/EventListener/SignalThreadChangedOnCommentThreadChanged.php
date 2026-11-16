<?php

declare(strict_types=1);

namespace App\Module\Review\EventListener;

use App\Module\Review\Event\CommentThreadChanged;
use App\Module\Review\Service\DocumentLiveSignals;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A thread change shows on the review page, and the waiting count of the row on the list. */
#[AsEventListener]
final readonly class SignalThreadChangedOnCommentThreadChanged
{
    public function __construct(
        private DocumentLiveSignals $signals,
    ) {
    }

    public function __invoke(CommentThreadChanged $event): void
    {
        $this->signals->threadChanged($event->projectId, $event->documentId, $event->commentId, $event->scope);
        $this->signals->documentsChanged($event->projectId, $event->documentId);
    }
}
