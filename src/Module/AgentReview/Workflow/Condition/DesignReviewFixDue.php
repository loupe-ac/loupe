<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Workflow\Condition;

use App\Module\AgentReview\Workflow\DocumentReviewState;

/** The review of the current version of a draft document with the tag is not answered and asks for a fix round. */
final readonly class DesignReviewFixDue extends DesignReviewCondition
{
    #[\Override]
    public static function key(): string
    {
        return 'design_review.fix_due';
    }

    #[\Override]
    protected function matches(DocumentReviewState $document, int $rounds): bool
    {
        return $document->fixDue($rounds);
    }
}
