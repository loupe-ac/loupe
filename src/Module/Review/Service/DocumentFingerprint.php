<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Module\Review\Entity\Document;

/** A short value that changes when what the rename form shows about a document changes. */
final readonly class DocumentFingerprint
{
    public static function of(Document $document): string
    {
        return substr(hash('sha256', $document->title."\n".(null === $document->archivedAt ? '0' : '1')), 0, 16);
    }
}
