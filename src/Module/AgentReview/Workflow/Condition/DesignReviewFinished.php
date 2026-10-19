<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Workflow\Condition;

use App\Module\AgentReview\Workflow\DocumentReviewFacts;
use App\Module\AgentReview\Workflow\DocumentReviewState;
use App\Module\Workflow\Contract\EndsReviewLoop;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\ParameterValue;

/** The review loop of every draft document with the tag passed, or used all its failed rounds. */
final readonly class DesignReviewFinished extends DesignReviewCondition implements EndsReviewLoop
{
    #[\Override]
    public static function key(): string
    {
        return 'design_review.finished';
    }

    /** `publish-documents` publishes every draft with the tag, so one unfinished draft holds them all. */
    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        $documents = $facts->get(DocumentReviewFacts::class)->withTag(ParameterValue::string($params, 'tag'));

        return [] !== $documents && \count($documents) === \count($this->matching($facts, $params));
    }

    #[\Override]
    public function roundLimit(array $params): int
    {
        return ParameterValue::int($params, 'rounds');
    }

    #[\Override]
    protected function matches(DocumentReviewState $document, int $rounds): bool
    {
        return $document->finished($rounds);
    }
}
