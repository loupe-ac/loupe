<?php

declare(strict_types=1);

namespace App\Module\Review\EventListener;

use App\Module\Review\Event\DocumentStatusChanged;
use App\Module\Review\Service\DocumentLiveSignals;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A status, an archive or a tag change shows on the review page and on the list. */
#[AsEventListener]
final readonly class SignalDocumentStateOnDocumentStatusChanged
{
    public function __construct(
        private DocumentLiveSignals $signals,
    ) {
    }

    public function __invoke(DocumentStatusChanged $event): void
    {
        $this->signals->documentStateChanged($event->projectId, $event->documentId);
        $this->signals->documentsChanged($event->projectId, $event->documentId);
    }
}
