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
    private const string UNCONFIRMED = 'Dismissed by Loupe: GitHub did not confirm that the approved commit is still the head.';

    public function __construct(
        private PullRequestApprovers $approvers,
        private ForgePullRequestRepository $forgePullRequests,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * The forge's own head is read before and after the post, because the stored row can lag behind a push.
     * An App approval on another commit is dismissed before the post, and one that a push overtook is dismissed at once.
     * When the second read fails, the new approval is dismissed too and the failure is thrown, so the write is retried.
     *
     * @return bool false when the pull request is no longer open or its head is not $headSha, so no approval stands
     *
     * @throws PullRequestWriteFailed
     */
    public function approve(ForgePullRequest $pullRequest, string $headSha, string $body): bool
    {
        $approver = $this->approvers->for($pullRequest->forge) ?? throw new PullRequestWriteFailed('no_writer', permanent: true);
        $id = $pullRequest->id ?? throw new \LogicException('A stored pull request has an id.');

        /** @var list{?string, ?string, ?string}|null $row */
        $row = $this->em->wrapInTransaction(function () use ($id): ?array {
            $row = $this->forgePullRequests->findForUpdate($id);

            return null === $row || PullRequestState::Open !== $row->state ? null : [$row->headSha, $row->appApprovalId, $row->appApprovalSha];
        });
        if (null === $row) {
            return false;
        }
        [$storedHead, $standingId, $standingSha] = $row;
        $forgeHead = $approver->openHead($pullRequest);
        if (null !== $standingId && null !== $forgeHead && $standingSha !== $forgeHead) {
            $this->withdraw($approver, $pullRequest, $id, $standingId, self::OVERTAKEN);
        }
        if ($storedHead !== $headSha || $forgeHead !== $headSha) {
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
        } catch (PullRequestWriteFailed $e) {
            $this->withdraw($approver, $pullRequest, $id, $approval->reviewId, self::UNCONFIRMED);

            throw $e;
        }

        $this->withdraw($approver, $pullRequest, $id, $approval->reviewId, self::OVERTAKEN);

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

    /** @throws PullRequestWriteFailed */
    private function withdraw(PullRequestApprover $approver, ForgePullRequest $pullRequest, Uuid $id, string $reviewId, string $message): void
    {
        $approver->dismiss($pullRequest, $reviewId, $message);
        $this->forget($id, $reviewId);
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
