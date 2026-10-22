<?php

declare(strict_types=1);

namespace App\Module\Review\EventListener;

use App\Module\Review\Event\DocumentStatusChanged;
use App\Module\Review\Service\DraftStateSignal;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A publish, an undo, a revision or an archive changes what the band of the document shows. */
#[AsEventListener]
final readonly class SignalDraftStateOnDocumentStatusChanged
{
    public function __construct(
        private DraftStateSignal $signal,
    ) {
    }

    public function __invoke(DocumentStatusChanged $event): void
    {
        $this->signal->send($event->projectId, $event->documentId);
    }
}
