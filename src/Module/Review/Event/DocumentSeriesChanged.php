<?php

declare(strict_types=1);

namespace App\Module\Review\Event;

use Symfony\Component\Uid\Uuid;

/**
 * The place of a document in a series, or the name of a series, changed.
 * Dispatched after the flush. A series rename names no document.
 */
final readonly class DocumentSeriesChanged
{
    public function __construct(
        public Uuid $projectId,
        public ?Uuid $documentId = null,
    ) {
    }
}
