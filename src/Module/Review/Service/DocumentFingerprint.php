<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Module\Review\Entity\Document;

/** A short value that changes when the title, the status, the archive state, the tags or the version count changes, the same changes that the live signal reports. */
final readonly class DocumentFingerprint
{
    public static function of(Document $document): string
    {
        $tags = $document->tags->map(static fn ($tag): string => $tag->name)->toArray();
        sort($tags);

        return substr(hash('sha256', implode("\n", [
            $document->title,
            $document->status->value,
            null === $document->archivedAt ? '0' : '1',
            (string) $document->versions->count(),
            implode(',', $tags),
        ])), 0, 16);
    }
}
