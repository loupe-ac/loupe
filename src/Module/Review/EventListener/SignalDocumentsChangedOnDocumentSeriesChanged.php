<?php

declare(strict_types=1);

namespace App\Module\Review\EventListener;

use App\Module\Review\Event\DocumentSeriesChanged;
use App\Module\Review\Service\DocumentLiveSignals;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class SignalDocumentsChangedOnDocumentSeriesChanged
{
    public function __construct(
        private DocumentLiveSignals $signals,
    ) {
    }

    public function __invoke(DocumentSeriesChanged $event): void
    {
        $this->signals->documentsChanged($event->projectId, $event->documentId);
    }
}
