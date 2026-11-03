<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Document;

final readonly class UpdateDocumentCommand
{
    /**
     * Every field is optional, and null means "leave it alone".
     *
     * @param list<string>|null   $tagNames       the complete set of raw tag names
     * @param list<Document>|null $references     the complete set of documents this one points at
     * @param bool                $placesInSeries true when the call names a series or an ordinal, even a null one
     * @param ?string             $seriesName     with a null ordinal, null takes the document out of its series
     * @param ?bool               $archived       true archives the document, false restores it
     * @param ?string             $archiveReason  required with $archived true
     */
    public function __construct(
        public Document $document,
        public ?string $title = null,
        public ?array $tagNames = null,
        public ?array $references = null,
        public bool $placesInSeries = false,
        public ?string $seriesName = null,
        public ?int $seriesOrdinal = null,
        public ?bool $archived = null,
        public ?string $archiveReason = null,
    ) {
    }
}
