<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Command;

use App\Module\Review\Entity\Document;

final readonly class SeedFailedDocumentReviewCommand
{
    public function __construct(
        public Document $document,
    ) {
    }
}
