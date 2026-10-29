<?php

declare(strict_types=1);

namespace App\Module\Forge\Command;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestMergeability;
use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\Event\PullRequestStateChanged;
use App\Module\Forge\Messenger\RefreshPullRequestState;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Forge\Service\ApprovalCoverageReaders;
use App\Module\Forge\Service\PullRequestChangeFingerprintReaders;
use App\Module\Forge\Service\PullRequestStateReaders;
use App\Module\Forge\Service\PullRequestUnreadable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Uid\Uuid;

/**
 * Reads one pull request and stores what the forge said. The row stays locked
 * through the read, so two refreshes of one row never interleave.
 */
final readonly class ReadPullRequestStateHandler
{
    /** A forge computes mergeability in the background, so an unknown answer is read again this many times. */
    public const int MAX_UNKNOWN_RETRIES = 4;

    private const int FIRST_RETRY_SECONDS = 30;

    public function __construct(
        private ForgePullRequestRepository $forgePullRequests,
        private PullRequestStateReaders $readers,
        private ApprovalCoverageReaders $coverageReaders,
        private PullRequestChangeFingerprintReaders $fingerprintReaders,
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private EventDispatcherInterface $events,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ReadPullRequestStateCommand $command): void
    {
        if (!Uuid::isValid($command->pullRequestId)) {
            return;
        }

        // A transient failure leaves the transaction as a value, so the rollback does not close the EntityManager.
        $transient = $this->em->wrapInTransaction(function () use ($command): ?PullRequestUnreadable {
            $pullRequest = $this->forgePullRequests->findForUpdate(Uuid::fromString($command->pullRequestId));
            $reader = null === $pullRequest ? null : $this->readers->for($pullRequest->forge);
            if (null === $pullRequest || null === $reader) {
                return null;
            }

            // A redelivered review keeps its id, so its verdict has already gone out.
            $verdict = null !== $command->reviewId && $pullRequest->hasAnnouncedReview($command->reviewId) ? null : $command->verdict;

            if ($pullRequest->refreshedAt >= $command->requestedAt) {
                $this->announceVerdict($verdict, $command->reviewId, $pullRequest);

                return null;
            }

            // Stamped before the read, so a hint that arrives during the read is not skipped.
            $readStartedAt = $this->clock->now();
            $previous = $pullRequest->snapshot();
            try {
                $current = $reader->read($pullRequest);
            } catch (PullRequestUnreadable $e) {
                $this->logger->warning('forge.pull_request_refresh_failed', [
                    'pullRequestId' => (string) $pullRequest->id,
                    'forge' => $pullRequest->forge,
                    'reason' => $e->reason,
                ]);
                // The retry carries the verdict, so a transient failure leaves the review to it.
                if ($e->transient) {
                    return $e;
                }
                // No stamp, so a row never read keeps a null refreshedAt and the card shows it as not reported.
                $this->announceVerdict($verdict, $command->reviewId, $pullRequest);

                return null;
            }

            $latestApprovalBefore = $pullRequest->latestApprovalId;
            $coveredBefore = $pullRequest->coveredSha;
            $uncoveredBefore = $pullRequest->uncoveredSha;
            $changeBefore = $pullRequest->changeFingerprint;
            $linesBefore = $pullRequest->linesFingerprint;
            $closedBefore = $pullRequest->closedAt;
            $mergedBefore = $pullRequest->mergedAt;
            $pullRequest->apply($current);
            $pullRequest->refreshedAt = $readStartedAt;
            $this->judgeCoverage($pullRequest);
            $this->readChangeFingerprint($pullRequest, $previous->baseBranch);
            $pullRequest->settleReadyToMerge($current->readyToMerge, $readStartedAt);
            $pullRequest->settleStartTimes($readStartedAt);
            $settled = $pullRequest->snapshot();
            $this->retryUnknownMergeability($pullRequest);

            // One event per read, so Board asks at most one fix for a verdict and a state change together.
            if (null !== $verdict && null !== $command->reviewId) {
                $pullRequest->recordAnnouncedReview($command->reviewId);
            }
            // The snapshot leaves the approvals and the fingerprints out, so a change to either of them is compared here.
            $coverageChanged = $pullRequest->coveredSha !== $coveredBefore
                || $pullRequest->uncoveredSha !== $uncoveredBefore
                || $pullRequest->latestApprovalId !== $latestApprovalBefore;
            $fingerprintChanged = $pullRequest->changeFingerprint !== $changeBefore || $pullRequest->linesFingerprint !== $linesBefore;
            // The snapshot compares no time, yet a finish time decides whether a card link counts as work.
            $finishChanged = $pullRequest->closedAt != $closedBefore || $pullRequest->mergedAt != $mergedBefore;
            if (!$settled->equals($previous) || null !== $verdict || $coverageChanged || $fingerprintChanged || $finishChanged) {
                $this->events->dispatch(new PullRequestStateChanged($pullRequest, $previous, $settled, $verdict));
            }

            return null;
        });

        // The retry strategy of the transport reads it again. The sweep skips a closed row, so a reopen would otherwise stay unread.
        if (null !== $transient) {
            throw $transient;
        }
    }

    /** A read that stored no new state still delivers its verdict, against the stored state. */
    private function announceVerdict(?PullRequestReview $verdict, ?string $reviewId, ForgePullRequest $pullRequest): void
    {
        if (null !== $verdict) {
            if (null !== $reviewId) {
                $pullRequest->recordAnnouncedReview($reviewId);
            }
            $snapshot = $pullRequest->snapshot();
            $this->events->dispatch(new PullRequestStateChanged($pullRequest, $snapshot, $snapshot, $verdict));
        }
    }

    /** A head that the forge already judged not covered is not asked again, until a new approval or a new head. */
    private function judgeCoverage(ForgePullRequest $pullRequest): void
    {
        $head = $pullRequest->headSha;
        if (PullRequestState::Open !== $pullRequest->state || null === $pullRequest->coveredSha || null === $head || $head === $pullRequest->coveredSha || $head === $pullRequest->uncoveredSha) {
            return;
        }

        $reader = $this->coverageReaders->for($pullRequest->forge);
        if (null !== $reader) {
            $pullRequest->recordCoverage($reader->read($pullRequest));
        }
    }

    /**
     * A head stays recorded with no fingerprint when the forge has no reader or gives none. It is asked again when the head or the base moves.
     * A pull request that is not open forgets its fingerprint, because its base can change before a reopen.
     */
    private function readChangeFingerprint(ForgePullRequest $pullRequest, ?string $baseBefore): void
    {
        if (PullRequestState::Open !== $pullRequest->state) {
            $pullRequest->changeFingerprintSha = $pullRequest->changeFingerprint = $pullRequest->linesFingerprint = null;

            return;
        }
        $head = $pullRequest->headSha;
        if (null === $head || '' === $head) {
            return;
        }
        if ($head === $pullRequest->changeFingerprintSha && $baseBefore === $pullRequest->baseBranch) {
            return;
        }

        $reader = $this->fingerprintReaders->for($pullRequest->forge);
        $pullRequest->recordChangeFingerprint($head, $reader?->read($pullRequest, $head));
    }

    private function retryUnknownMergeability(ForgePullRequest $pullRequest): void
    {
        $unknown = PullRequestState::Open === $pullRequest->state && PullRequestMergeability::Unknown === $pullRequest->mergeability;
        if (!$unknown) {
            $pullRequest->refreshAttempts = 0;
        }
        if (!$unknown || $pullRequest->refreshAttempts >= self::MAX_UNKNOWN_RETRIES) {
            $pullRequest->nextRefreshAt = null;

            return;
        }

        $delaySeconds = self::FIRST_RETRY_SECONDS * 2 ** $pullRequest->refreshAttempts;
        ++$pullRequest->refreshAttempts;
        $pullRequest->nextRefreshAt = $this->clock->now()->modify(\sprintf('+%d seconds', $delaySeconds));

        // Requested for when it runs, so the skip rule never mistakes it for the read that queued it.
        $this->bus->dispatch(
            new RefreshPullRequestState((string) $pullRequest->id, $pullRequest->nextRefreshAt),
            [new DelayStamp($delaySeconds * 1000)],
        );
    }
}
