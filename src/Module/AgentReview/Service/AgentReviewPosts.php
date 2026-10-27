<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Service;

use App\Module\AgentReview\Entity\AgentReview;
use App\Module\AgentReview\Repository\AgentReviewPostRepository;
use App\Module\AgentReview\Repository\AgentReviewRepository;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardPullRequestRepository;

/** The one answer to which checks of a card are due. The unposted fact and the publisher both read it, so they cannot disagree. */
final readonly class AgentReviewPosts
{
    public function __construct(
        private CardPullRequestRepository $cardPullRequests,
        private AgentReviewRepository $agentReviews,
        private AgentReviewPostRepository $agentReviewPosts,
        private AgentReviewOverride $override,
    ) {
    }

    /**
     * The reviews of the card that no check shows on their own head, then the reviews of the card that the
     * current head of an open GitHub pull request carries and that no check shows on that head. A check that shows
     * another conclusion, approver or name than its review now earns, such as after an override starts, ends or changes hands, is due again. Oldest review first.
     *
     * @return list<DuePost>
     */
    public function dueForCard(Card $card): array
    {
        $open = $this->cardPullRequests->findOpenGitHubForCard($card);
        $due = array_map(
            fn (AgentReview $review): DuePost => new DuePost($review, $review->pullRequest, $review->headSha, false, null, mb_strtolower($review->headSha) === mb_strtolower($review->pullRequest->headSha ?? '') ? $this->override->approverOf($review, $review->pullRequest) : null),
            $this->agentReviews->findUnpostedOfCard($card, $open),
        );

        $carried = [];
        $latest = $this->agentReviews->findLatestOfHeads($open);
        foreach ($open as $pullRequest) {
            $review = $latest[(string) $pullRequest->id] ?? null;
            $head = mb_strtolower($pullRequest->headSha ?? '');
            if (null === $review || !$review->card->id?->equals($card->id)) {
                continue;
            }
            if (mb_strtolower($review->headSha) !== $head) {
                $carried[] = [$review, $pullRequest, $head];
            } elseif (null !== $review->postedAt) {
                $own = new DuePost($review, $pullRequest, $head, false, null, $this->override->approverOf($review, $pullRequest));
                if ($own->stale()) {
                    $due[] = $own;
                }
            }
        }

        $posts = [];
        foreach ($this->agentReviewPosts->findOfReviews(array_map(static fn (array $entry): AgentReview => $entry[0], $carried)) as $post) {
            $posts[$post->review->id.' '.$post->headSha] = $post;
        }
        foreach ($carried as [$review, $pullRequest, $head]) {
            $post = $posts[$review->id.' '.$head] ?? null;
            $carriedDue = new DuePost($review, $pullRequest, $head, true, $post, $this->override->approverOf($review, $pullRequest));
            if (null === $post?->postedAt || $carriedDue->stale()) {
                $due[] = $carriedDue;
            }
        }

        usort($due, static fn (DuePost $a, DuePost $b): int => [$a->review->createdAt, (string) $a->review->id, $a->carried] <=> [$b->review->createdAt, (string) $b->review->id, $b->carried]);

        return $due;
    }
}
