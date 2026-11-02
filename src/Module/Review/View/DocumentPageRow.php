<?php

declare(strict_types=1);

namespace App\Module\Review\View;

use App\Module\Review\Entity\Document;

/** One document of a page, with the title rank that put it there. */
final readonly class DocumentPageRow
{
    public function __construct(
        public Document $document,
        /** 1 when every wanted word is in the title, else 0. Always 0 outside a ranked search. */
        public int $titleHit = 0,
    ) {
    }
}
