<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\CardPullRequest;

/** The stored pull request states of a set of cards. */
final readonly class PullRequestStates
{
    /**
     * @param array<string, PullRequestStateView> $byPullRequest keyed by card pull request id
     */
    public function __construct(
        public array $byPullRequest = [],
    ) {
    }

    public function of(CardPullRequest $link): ?PullRequestStateView
    {
        return $this->byPullRequest[(string) $link->id] ?? null;
    }
}
