<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Workflow;

use App\Module\AgentReview\Entity\AgentReviewConclusion;

/** The conclusion of the newest agent review of each document of a card, whatever the status of the document. */
final readonly class DocumentReviewConclusionFacts
{
    /** @param array<string, AgentReviewConclusion> $newestByDocument keyed by document id, with no key for a document that has no review for the card */
    public function __construct(
        public array $newestByDocument,
    ) {
    }
}
