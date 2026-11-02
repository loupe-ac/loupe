<?php

declare(strict_types=1);

namespace App\Module\Review\View;

use App\Module\Review\Entity\Document;

/**
 * One row of a page of the document API: the document and its current version.
 * The two link lists are empty when the page was told not to read them.
 *
 * @phpstan-type Link array{documentId: string, title: string, archived: bool}
 */
final readonly class DocumentPageItem
{
    /**
     * @param list<Link> $references
     * @param list<Link> $referencedBy
     */
    public function __construct(
        public Document $document,
        public int $versionNumber,
        public ?string $description,
        public array $references = [],
        public array $referencedBy = [],
    ) {
    }
}
