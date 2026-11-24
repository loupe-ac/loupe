<?php

declare(strict_types=1);

namespace App\Module\Workflow\Engine;

use App\Module\Workflow\Contract\CardWorkerWaits;
use App\Module\Workflow\Contract\PullRequestFacts;
use App\Module\Workflow\Contract\PullRequestList;
use App\Module\Workflow\Contract\PullRequestState;
use App\Module\Workflow\Contract\WaitConditionCheck;
use App\Module\Workflow\Contract\WaitEnd;
use App\Module\Workflow\Service\WaitExpressions;

/**
 * Tests the open wait of the card on the facts the pass loaded, and ends it when a condition is true, when the
 * pull request of a condition left the card, or when the time limit passed. It ends the wait whatever the state of
 * its run, so the result is recorded while the worker still finishes its turn.
 */
final readonly class WaitStep
{
    public function __construct(
        private CardWorkerWaits $waits,
        private WaitExpressions $expressions,
    ) {
    }

    public function __invoke(Evaluation $run): void
    {
        $wait = $this->waits->openFor($run->card->id);
        if (null === $wait) {
            return;
        }

        $facts = $run->facts;
        $listReadable = null === $facts->unreadable(PullRequestList::class);
        $gone = false;
        $met = [];
        foreach ($wait->conditions as $condition) {
            $scoped = $facts;
            if (null !== $condition->pullRequestId && $listReadable) {
                $bound = array_find($facts->pullRequests(), static fn (PullRequestFacts $pullRequest): bool => $pullRequest->id?->equals($condition->pullRequestId) ?? false);
                if (null === $bound || PullRequestState::Open !== $bound->state) {
                    $gone = true;

                    continue;
                }
                $scoped = $facts->withPullRequest($bound);
            }

            $expression = $this->expressions->of($run->template, $condition->key, $condition->params);
            if ($expression instanceof WaitConditionCheck || null !== $expression->unreadable($scoped)) {
                continue;
            }
            if ($expression->evaluate($scoped)) {
                $met[] = $condition;
            }
        }

        $end = match (true) {
            $gone => WaitEnd::SubjectGone,
            [] !== $met => WaitEnd::Condition,
            $wait->limitAt <= $run->now => WaitEnd::TimedOut,
            default => null,
        };
        if (null === $end) {
            return;
        }

        $this->waits->end($wait->id, $end, WaitEnd::Condition === $end ? $met : []);
        $run->factsStale = true;
    }
}
