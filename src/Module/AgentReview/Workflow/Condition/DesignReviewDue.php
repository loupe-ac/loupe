<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Workflow\Condition;

use App\Module\AgentReview\Workflow\DocumentReviewState;

/** A draft document with the tag has a version that no review read, or a review that a fix round answered, and its loop is not finished. */
final readonly class DesignReviewDue extends DesignReviewCondition
{
    #[\Override]
    public static function key(): string
    {
        return 'design_review.due';
    }

    #[\Override]
    protected function matches(DocumentReviewState $document, int $rounds): bool
    {
        return $document->due($rounds);
    }
}
