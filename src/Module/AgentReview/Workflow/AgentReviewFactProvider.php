<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Workflow;

use App\Module\AgentReview\Entity\AgentReview;
use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\AgentReview\Repository\AgentReviewRepository;
use App\Module\AgentReview\Service\AgentReviewOverride;
use App\Module\AgentReview\Service\AgentReviewPosts;
use App\Module\AgentReview\Service\UncommentedAgentReviews;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\CardPullRequests;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\FactProvider;

final readonly class AgentReviewFactProvider implements FactProvider
{
    public function __construct(
        private CardRepository $cards,
        private CardPullRequests $trackedPullRequests,
        private AgentReviewRepository $agentReviews,
        private AgentReviewPosts $posts,
        private AgentReviewOverride $override,
        private UncommentedAgentReviews $uncommented,
        private WorkRequestRepository $workRequests,
    ) {
    }

    #[\Override]
    public function factsClass(): string
    {
        return AgentReviewFacts::class;
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
            static fn (ForgePullRequest $pullRequest): bool => PullRequestState::Open === $pullRequest->state,
        ));
        $latest = $this->agentReviews->findLatestOfHeads(array_values(array_filter($open, static fn (ForgePullRequest $pullRequest): bool => null !== $pullRequest->headSha)));
        $fixed = $this->workRequests->findAgentReviewFixesSettled($snapshot->id);
        $heads = array_map(function (ForgePullRequest $pullRequest) use ($latest, $fixed): ReviewedHead {
            $review = $latest[(string) $pullRequest->id] ?? null;
            $settled = $fixed[$pullRequest->number] ?? null;

            return new ReviewedHead(
                (string) $pullRequest->id,
                $pullRequest->headSha ?? '',
                $review?->conclusion,
                AgentReviewConclusion::Failure === $review?->conclusion && null !== $settled && $settled > $review->createdAt,
                null === $review ? null : $this->override->approverOf($review, $pullRequest),
            );
        }, $open);

        return new AgentReviewFacts(
            $heads,
            [] !== $this->posts->dueForCard($card),
            array_map(static fn (AgentReview $review): string => (string) $review->id, $this->uncommented->among($latest)),
        );
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        if (!$facts instanceof AgentReviewFacts) {
            throw new \LogicException('The provider fingerprints its own facts.');
        }

        return [
            $facts->unposted,
            $facts->uncommented,
            array_map(static fn (ReviewedHead $head): array => [$head->pullRequestId, $head->headSha, $head->conclusion?->value, $head->answered, $head->overriddenBy], $facts->heads),
        ];
    }

    #[\Override]
    public function source(): string
    {
        return 'workflow.source.agent_review';
    }
}
