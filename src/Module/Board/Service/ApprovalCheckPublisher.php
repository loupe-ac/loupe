<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\Service\PullRequestCheckConclusion;
use App\Module\Forge\Service\PullRequestCheckFailed;
use App\Module\Forge\Service\PullRequestCheckWriters;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Posts the approval check of a pull request. Loupe never posts an incomplete run, so a waiting pull request has no run. */
final readonly class ApprovalCheckPublisher
{
    public const string NAME = ForgePullRequest::APPROVAL_CHECK_NAME;

    private const string PASSED = 'success';

    public function __construct(
        private PullRequestCheckWriters $writers,
        private TranslatorInterface $translator,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Passes the check on $headSha, which is the head that the rule read.
     *
     * @return bool false when nothing was posted: the pull request is closed, its head moved, the check already passed there, or no writer serves the forge
     *
     * @throws PullRequestCheckFailed
     */
    public function pass(ForgePullRequest $pullRequest, string $headSha): bool
    {
        if (PullRequestState::Open !== $pullRequest->state || $pullRequest->headSha !== $headSha) {
            return false;
        }
        $onHead = $pullRequest->approvalCheckSha === $headSha;
        if ($onHead && self::PASSED === $pullRequest->approvalCheckConclusion) {
            return false;
        }

        $runId = $this->write(
            $pullRequest,
            $headSha,
            PullRequestCheckConclusion::Success,
            $this->translator->trans('board.approval_check.title_passed'),
            $this->translator->trans('board.approval_check.summary_passed'),
            $onHead ? $pullRequest->approvalCheckRunId : null,
        );
        if (null === $runId) {
            return false;
        }

        $pullRequest->approvalCheckRunId = (string) $runId;
        $pullRequest->approvalCheckSha = $headSha;
        $pullRequest->approvalCheckConclusion = self::PASSED;
        $pullRequest->approvalCheckPassedAt = $this->clock->now();
        $this->em->flush();

        return true;
    }

    /**
     * Turns the passed run on the head to a failure.
     *
     * @return bool false when nothing was posted: no run passed on the current head, or no writer serves the forge
     *
     * @throws PullRequestCheckFailed
     */
    public function revoke(ForgePullRequest $pullRequest): bool
    {
        $headSha = $pullRequest->headSha;
        $runId = $pullRequest->approvalCheckRunId;
        if (null === $headSha || null === $runId || $pullRequest->approvalCheckSha !== $headSha || self::PASSED !== $pullRequest->approvalCheckConclusion) {
            return false;
        }

        $written = $this->write(
            $pullRequest,
            $headSha,
            PullRequestCheckConclusion::Failure,
            $this->translator->trans('board.approval_check.title_revoked'),
            $this->translator->trans('board.approval_check.summary_revoked'),
            $runId,
        );
        if (null === $written) {
            return false;
        }

        $pullRequest->approvalCheckConclusion = PullRequestCheckConclusion::Failure->value;
        $this->em->flush();

        return true;
    }

    /** @throws PullRequestCheckFailed */
    private function write(ForgePullRequest $pullRequest, string $headSha, PullRequestCheckConclusion $conclusion, string $title, string $summary, ?string $runId): ?int
    {
        $writer = $this->writers->for($pullRequest->forge);
        if (null === $writer) {
            return null;
        }

        return $writer->publish($pullRequest, self::NAME, $headSha, $conclusion, $title, $summary, null === $runId ? null : (int) $runId, []);
    }
}
