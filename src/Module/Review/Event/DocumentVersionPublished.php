<?php

declare(strict_types=1);

namespace App\Module\Review\Event;

use Symfony\Component\Uid\Uuid;

/** A revision committed a new version of a document. Dispatched after the commit, with ids and the number. */
final readonly class DocumentVersionPublished
{
    public function __construct(
        public Uuid $projectId,
        public Uuid $documentId,
        public int $versionNumber,
    ) {
    }
}
