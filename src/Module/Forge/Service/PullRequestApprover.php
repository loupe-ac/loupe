<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use App\Module\Forge\Entity\ForgePullRequest;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Approves a pull request of one forge as Loupe itself, and dismisses that approval. A forge module implements it.
 * The approval is the forge account of Loupe, never a person's account.
 */
#[AutoconfigureTag('app.pull_request_approver')]
interface PullRequestApprover
{
    /** @param string $forge the forge's slug, such as `github` */
    public function supports(string $forge): bool;

    /** Whether Loupe can write a review on this pull request now, for example because its repository has an active installation. */
    public function canApprove(ForgePullRequest $pullRequest): bool;

    /**
     * The head commit that the forge reports now, read past the stored row, or null when the pull request is not open.
     *
     * @throws PullRequestWriteFailed when the forge does not answer
     */
    public function openHead(ForgePullRequest $pullRequest): ?string;

    /**
     * Approves the named commit, so a later push cannot ride on the approval.
     *
     * @throws PullRequestWriteFailed when the forge does not take the review
     */
    public function approve(ForgePullRequest $pullRequest, string $headSha, string $body): PullRequestApproval;

    /**
     * Dismisses an approval that Loupe posted. An approval that is no longer standing counts as dismissed.
     *
     * @throws PullRequestWriteFailed when the forge does not take the dismissal
     */
    public function dismiss(ForgePullRequest $pullRequest, string $reviewId, string $message): void;
}
