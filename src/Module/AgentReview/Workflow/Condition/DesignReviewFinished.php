<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Workflow\Condition;

use App\Module\AgentReview\Workflow\DocumentReviewState;

/** The review loop of a draft document with the tag passed, or used all its failed rounds. */
final readonly class DesignReviewFinished extends DesignReviewCondition
{
    #[\Override]
    public static function key(): string
    {
        return 'design_review.finished';
    }

    #[\Override]
    protected function matches(DocumentReviewState $document, int $rounds): bool
    {
        return $document->finished($rounds);
    }
}
