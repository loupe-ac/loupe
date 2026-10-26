<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Approves a pull request as Loupe, and dismisses that approval. A write stores the review it made on the row,
 * so the next evaluation does not wait for the next read. The forge call holds no lock.
 */
final readonly class ForgePullRequestApprovals
{
    private const string OVERTAKEN = 'Dismissed by Loupe: a new commit arrived while Loupe approved an earlier one.';

    public function __construct(
        private PullRequestApprovers $approvers,
        private ForgePullRequestRepository $forgePullRequests,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * The forge's own head is read before and after the post, because the stored row can lag behind a push.
     * An approval that a push overtook is dismissed at once.
     *
     * @return bool false when the pull request is no longer open or its head is not $headSha, so no approval stands
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
        if (!$current || $approver->openHead($pullRequest) !== $headSha) {
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
        try {
            if ($approver->openHead($pullRequest) === $headSha) {
                return true;
            }
        } catch (PullRequestWriteFailed) {
            // The approval was posted, and the row records it; only the check for a push during the post failed.
            return true;
        }

        $approver->dismiss($pullRequest, $approval->reviewId, self::OVERTAKEN);
        $this->forget($id, $approval->reviewId);

        return false;
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
        $this->forget($id, $reviewId);

        return true;
    }

    private function forget(Uuid $id, string $reviewId): void
    {
        $this->em->wrapInTransaction(function () use ($id, $reviewId): void {
            $row = $this->forgePullRequests->findForUpdate($id);
            if (null !== $row && $row->appApprovalId === $reviewId) {
                $row->appApprovalId = null;
                $row->appApprovalSha = null;
            }
        });
    }

    public function canApprove(ForgePullRequest $pullRequest): bool
    {
        return $this->approvers->for($pullRequest->forge)?->canApprove($pullRequest) ?? false;
    }
}
