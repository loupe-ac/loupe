<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Module\Review\Entity\Document;

/** A short value that changes when the title, the archive state or the version count changes, the same changes that the live signal reports. */
final readonly class DocumentFingerprint
{
    public static function of(Document $document): string
    {
        return substr(hash('sha256', $document->title."\n".(null === $document->archivedAt ? '0' : '1')."\n".$document->versions->count()), 0, 16);
    }
}
