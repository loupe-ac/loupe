<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Service\CardPullRequests;
use App\Module\Bridge\ValueObject\WorkRequestContext;
use App\Module\Workflow\Contract\EpicBranches;
use App\Module\Workflow\Contract\PullRequestFacts;

/** Builds what a work request of a card holds about the card when it opens. */
final readonly class WorkRequestContexts
{
    public function __construct(
        private CardPullRequests $trackedPullRequests,
        private CardPullRequestRepository $cardPullRequests,
        private EpicBranches $epicBranches,
    ) {
    }

    /** A link URL or a head of another shape stays out, because a bridge fills commands with these values. */
    public function forCard(Card $card, ?PullRequestFacts $facts, ?string $documentId = null, ?string $reason = null): WorkRequestContext
    {
        $reason ??= $facts?->fixReason();
        $epicBranch = null === $card->parent ? null : $this->epicBranches->of($card->project->id ?? throw new \LogicException('A stored card has a stored project.'), $card->parent->number);
        $pullRequest = $this->trackedPullRequests->subjectOf($this->trackedPullRequests->forCard($card), $facts);
        if (null === $pullRequest) {
            return new WorkRequestContext(reason: $reason, documentId: $documentId, epicBranch: $epicBranch);
        }

        $url = $this->cardPullRequests->findUrlOfPullRequest($card, $pullRequest->forge, $pullRequest->repository, $pullRequest->number);
        $headSha = null === $pullRequest->headSha ? null : mb_strtolower($pullRequest->headSha);

        return new WorkRequestContext(
            pullRequestNumber: $pullRequest->number,
            pullRequestUrl: null !== $url && WorkRequestContext::acceptsUrl($url) ? $url : null,
            headSha: null !== $headSha && WorkRequestContext::acceptsHeadSha($headSha) ? $headSha : null,
            reason: $reason,
            documentId: $documentId,
            epicBranch: $epicBranch,
        );
    }
}
