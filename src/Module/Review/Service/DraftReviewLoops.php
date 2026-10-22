<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Module\Review\Entity\Document;

/**
 * Tells whether a design review loop of a linked card waits to publish a draft document.
 * The AgentReview module implements it.
 */
interface DraftReviewLoops
{
    /** @return ?DraftReviewLoop null when no design review loop waits to publish the draft, and for a document that is not a draft */
    public function of(Document $document): ?DraftReviewLoop;
}
