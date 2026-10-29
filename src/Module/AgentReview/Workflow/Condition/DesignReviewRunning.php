<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Workflow\Condition;

use App\Module\AgentReview\Workflow\DocumentReviewState;
use App\Module\Workflow\Contract\Evidence;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\MarkerEvidence;
use App\Module\Workflow\Contract\ParameterValue;

/** A draft document with the tag has a review loop that is not finished. */
final readonly class DesignReviewRunning extends DesignReviewCondition implements MarkerEvidence
{
    #[\Override]
    public static function key(): string
    {
        return 'design_review.running';
    }

    #[\Override]
    public function evidence(Facts $facts, array $params): ?Evidence
    {
        $running = $this->matching($facts, $params)[0] ?? null;

        return null === $running ? null : new Evidence(['%round%' => min($running->usedRounds() + 1, ParameterValue::int($params, 'rounds')), '%limit%' => ParameterValue::int($params, 'rounds')]);
    }

    #[\Override]
    protected function matches(DocumentReviewState $document, int $rounds): bool
    {
        return !$document->finished($rounds);
    }
}
