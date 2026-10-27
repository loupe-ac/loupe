<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Workflow;

use App\Module\AgentReview\Entity\AgentReview;
use App\Module\AgentReview\Repository\AgentReviewRepository;
use App\Module\AgentReview\Service\AgentReviewPosts;
use App\Module\AgentReview\Service\UncommentedAgentReviews;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\CardPullRequests;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\FactProvider;

/** Kept apart from the review facts, so a post of the check or the comment never counts as a change of the review. */
final readonly class AgentReviewPostingFactProvider implements FactProvider
{
    public function __construct(
        private CardRepository $cards,
        private CardPullRequests $trackedPullRequests,
        private AgentReviewRepository $agentReviews,
        private AgentReviewPosts $posts,
        private UncommentedAgentReviews $uncommented,
    ) {
    }

    #[\Override]
    public function factsClass(): string
    {
        return AgentReviewPostingFacts::class;
    }

    #[\Override]
    public function isOn(): bool
    {
        return true;
    }

    #[\Override]
    public function build(CardSnapshot $snapshot): object
    {
        $card = $this->cards->find($snapshot->id) ?? throw new \LogicException('The card of the facts exists.');
        $open = array_values(array_filter(
            $this->trackedPullRequests->forCard($card),
            static fn (ForgePullRequest $pullRequest): bool => PullRequestState::Open === $pullRequest->state && null !== $pullRequest->headSha,
        ));
        $latest = $this->agentReviews->findLatestOfHeads($open);

        return new AgentReviewPostingFacts(
            [] !== $this->posts->dueForCard($card),
            array_map(static fn (AgentReview $review): string => (string) $review->id, $this->uncommented->among($latest)),
        );
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        if (!$facts instanceof AgentReviewPostingFacts) {
            throw new \LogicException('The provider fingerprints its own facts.');
        }

        return [$facts->unposted, $facts->uncommented];
    }

    #[\Override]
    public function source(): string
    {
        return 'workflow.source.agent_review';
    }
}
