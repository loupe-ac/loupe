<?php

declare(strict_types=1);

namespace App\Module\Workflow\Engine;

use App\Module\Workflow\Contract\EngineFact;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\PullRequestFacts;
use App\Module\Workflow\Contract\PullRequestState;
use App\Module\Workflow\Expression\Expression;
use App\Module\Workflow\Template\Rule;
use Symfony\Component\Uid\Uuid;

/**
 * Picks the pull request a rule acts on. A rule that reads the pull request takes the first open one,
 * in the order of the facts, that makes it true, and else the first open one.
 */
final readonly class RuleSubject
{
    public function bind(Rule $rule, Facts $facts): BoundRule
    {
        return $this->bindWhen($rule->when, $facts);
    }

    /** Picks the pull request for any condition, such as the one of a marker, the way bind() does for a rule. */
    public function bindWhen(Expression $when, Facts $facts): BoundRule
    {
        $readable = null === $when->unreadable($facts);
        $candidates = $this->candidates($when, $facts);
        if ([] === $candidates) {
            return new BoundRule($facts, $facts->pullRequest?->id, $readable && $when->evaluate($facts), array_any($when->reads(), static fn (EngineFact|string $key): bool => $key instanceof EngineFact && $key->bindsPullRequest()));
        }
        foreach ($candidates as $candidate) {
            $bound = $facts->withPullRequest($candidate);
            if ($readable && $when->evaluate($bound)) {
                return new BoundRule($bound, $candidate->id, true, true);
            }
        }

        return new BoundRule($facts->withPullRequest($candidates[0]), $candidates[0]->id, false, true);
    }

    /**
     * The facts of each pull request a condition can bind, in the order of the facts.
     * A condition that reads no pull request, or a card with no open one, answers the facts as given.
     *
     * @return non-empty-list<Facts>
     */
    public function subjects(Expression $when, Facts $facts): array
    {
        $candidates = $this->candidates($when, $facts);

        return [] === $candidates ? [$facts] : array_map($facts->withPullRequest(...), $candidates);
    }

    /** @return list<PullRequestFacts> */
    private function candidates(Expression $when, Facts $facts): array
    {
        if (!array_any($when->reads(), static fn (EngineFact|string $key): bool => $key instanceof EngineFact && $key->bindsPullRequest())) {
            return [];
        }

        return array_values(array_filter($facts->pullRequests(), static fn (PullRequestFacts $pullRequest): bool => PullRequestState::Open === $pullRequest->state));
    }

    /** The facts a rule pause reads its until on: the pull request it paused, while the card still links it, else the bound one. */
    public function paused(Rule $rule, Facts $facts, ?Uuid $stored): Facts
    {
        $bound = $this->bind($rule, $facts);
        $paused = !$bound->binds || null === $stored ? null
            : array_find($facts->pullRequests(), static fn (PullRequestFacts $pullRequest): bool => true === $stored->equals($pullRequest->id));

        return null === $paused ? $bound->facts : $facts->withPullRequest($paused);
    }

    /** The facts of the stored subject, while the card still links it, else null. A refill never reads another pull request. */
    public function stored(Facts $facts, ?Uuid $stored): ?Facts
    {
        $subject = null === $stored ? null : array_find($facts->pullRequests(), static fn (PullRequestFacts $pullRequest): bool => true === $stored->equals($pullRequest->id));

        return null === $subject ? null : $facts->withPullRequest($subject);
    }
}
