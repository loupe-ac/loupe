<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Approves a pull request as Loupe, and dismisses that approval. A write stores the review it made on the row,
 * so the next evaluation does not wait for the next read. The forge call holds no lock.
 */
final readonly class ForgePullRequestApprovals
{
    public function __construct(
        private PullRequestApprovers $approvers,
        private ForgePullRequestRepository $forgePullRequests,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @return bool false when the pull request is no longer open or its head is not $headSha, so nothing was posted
     *
     * @throws PullRequestWriteFailed
     */
    public function approve(ForgePullRequest $pullRequest, string $headSha, string $body): bool
    {
        $approver = $this->approvers->for($pullRequest->forge) ?? throw new PullRequestWriteFailed('no_writer', permanent: true);
        $id = $pullRequest->id ?? throw new \LogicException('A stored pull request has an id.');

        $current = $this->em->wrapInTransaction(function () use ($id, $headSha): bool {
            $row = $this->forgePullRequests->findForUpdate($id);

            return null !== $row && PullRequestState::Open === $row->state && $row->headSha === $headSha;
        });
        if (!$current) {
            return false;
        }

        $approval = $approver->approve($pullRequest, $headSha, $body);
        $this->em->wrapInTransaction(function () use ($id, $approval): void {
            $row = $this->forgePullRequests->findForUpdate($id);
            if (null !== $row) {
                $row->appApprovalId = $approval->reviewId;
                $row->appApprovalSha = $approval->commitSha;
            }
        });

        return true;
    }

    /**
     * @return bool false when the row holds no standing App approval, so nothing was dismissed
     *
     * @throws PullRequestWriteFailed
     */
    public function dismiss(ForgePullRequest $pullRequest, string $message): bool
    {
        $approver = $this->approvers->for($pullRequest->forge) ?? throw new PullRequestWriteFailed('no_writer', permanent: true);
        $id = $pullRequest->id ?? throw new \LogicException('A stored pull request has an id.');

        $reviewId = $this->em->wrapInTransaction(fn (): ?string => $this->forgePullRequests->findForUpdate($id)?->appApprovalId);
        if (null === $reviewId) {
            return false;
        }

        $approver->dismiss($pullRequest, $reviewId, $message);
        $this->em->wrapInTransaction(function () use ($id, $reviewId): void {
            $row = $this->forgePullRequests->findForUpdate($id);
            if (null !== $row && $row->appApprovalId === $reviewId) {
                $row->appApprovalId = null;
                $row->appApprovalSha = null;
            }
        });

        return true;
    }

    public function canApprove(ForgePullRequest $pullRequest): bool
    {
        return $this->approvers->for($pullRequest->forge)?->canApprove($pullRequest) ?? false;
    }
}
