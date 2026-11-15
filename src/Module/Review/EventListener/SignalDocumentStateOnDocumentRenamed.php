<?php

declare(strict_types=1);

namespace App\Module\Review\EventListener;

use App\Module\Review\Event\DocumentRenamed;
use App\Module\Review\Service\DocumentLiveSignals;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A new title shows on the review page, on the rename form and on the list. */
#[AsEventListener]
final readonly class SignalDocumentStateOnDocumentRenamed
{
    public function __construct(
        private DocumentLiveSignals $signals,
    ) {
    }

    public function __invoke(DocumentRenamed $event): void
    {
        $this->signals->documentStateChanged($event->projectId, $event->documentId);
        $this->signals->documentsChanged($event->projectId, $event->documentId);
    }
}
