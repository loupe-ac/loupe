<?php

declare(strict_types=1);

namespace App\Module\Review\EventListener;

use App\Module\Review\Event\DocumentVersionPublished;
use App\Module\Review\Service\DocumentLiveSignals;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A new version changes the review page of the document and its row on the list. */
#[AsEventListener]
final readonly class SignalVersionPublishedOnDocumentVersionPublished
{
    public function __construct(
        private DocumentLiveSignals $signals,
    ) {
    }

    public function __invoke(DocumentVersionPublished $event): void
    {
        $this->signals->versionPublished($event->projectId, $event->documentId, $event->versionNumber);
        $this->signals->documentsChanged($event->projectId, $event->documentId);
    }
}
