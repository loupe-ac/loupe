<?php

declare(strict_types=1);

namespace App\Module\Review\View;

use App\Module\Review\Entity\DocumentVersion;

/** One version with the number of comment threads on it. */
final readonly class DocumentVersionDetail
{
    public function __construct(
        public DocumentVersion $version,
        public int $threadCount,
    ) {
    }
}
