<?php

declare(strict_types=1);

namespace App\Module\Forge\Entity;

use App\Doctrine\Type\MicrosecondDateTimeImmutableType;
use App\Module\Forge\PullRequestSnapshot;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Forge\Service\ApprovalCoverage;
use App\Module\Forge\Service\PullRequestChangeFingerprint;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * The last state Loupe read of one pull request that a card of one project
 * links. The repository is stored lower-case, so one pull request has one row
 * whatever case a link spelled it in.
 */
#[ORM\Entity(repositoryClass: ForgePullRequestRepository::class)]
#[ORM\Table(name: 'forge_pull_requests')]
#[ORM\UniqueConstraint(name: self::KEY_CONSTRAINT, columns: ['project_id', 'forge', 'repository', 'number'])]
class ForgePullRequest
{
    public const string KEY_CONSTRAINT = 'uniq_forge_pull_requests_project_forge_repository_number';

    public const int ANNOUNCED_REVIEW_LIMIT = 50;

    /** The name of the check run that Loupe posts when a pull request may merge. */
    public const string APPROVAL_CHECK_NAME = 'Loupe / Approval';

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    #[ORM\Column(length: 20, enumType: PullRequestState::class)]
    public PullRequestState $state = PullRequestState::Open;

    #[ORM\Column]
    public bool $draft = false;

    #[ORM\Column(length: 64, nullable: true)]
    public ?string $headSha = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $baseBranch = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $headBranch = null;

    /** The title at the last read. A row read before this column existed holds null. */
    #[ORM\Column(length: 1024, nullable: true)]
    public ?string $title = null;

    /** The forge's id of the account that opened the pull request. */
    #[ORM\Column(length: 64, nullable: true)]
    public ?string $authorId = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $authorLogin = null;

    /** False for a row that no read has filled since the author columns exist, so a null author id means unread. */
    #[ORM\Column(options: ['default' => false])]
    public bool $authorRead = false;

    #[ORM\Column(length: 20, enumType: PullRequestChecks::class)]
    public PullRequestChecks $checks = PullRequestChecks::Pending;

    #[ORM\Column(length: 64, nullable: true)]
    public ?string $checksSha = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    public array $failedChecks = [];

    #[ORM\Column(length: 20, enumType: PullRequestMergeability::class)]
    public PullRequestMergeability $mergeability = PullRequestMergeability::Unknown;

    #[ORM\Column(length: 20, enumType: PullRequestReview::class)]
    public PullRequestReview $review = PullRequestReview::None;

    #[ORM\Column]
    public bool $readyToMerge = false;

    /** When the newest request for changes was made. A row read before this column existed holds null. */
    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $changesRequestedAt = null;

    /** Kept to the microsecond, because the refresh skip rule compares it with the request time of a message. */
    #[ORM\Column(type: MicrosecondDateTimeImmutableType::NAME, nullable: true, columnDefinition: 'TIMESTAMP(6) WITHOUT TIME ZONE DEFAULT NULL')]
    public ?\DateTimeImmutable $refreshedAt = null;

    /** The reads in a row that found the mergeability unknown. */
    #[ORM\Column]
    public int $refreshAttempts = 0;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $nextRefreshAt = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $openedAt = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $mergedAt = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $closedAt = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $approvedAt = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $readySince = null;

    /** The `readySince` for which the board last refreshed its cards after the stuck delay ended. */
    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $stuckAnnouncedFor = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $checksFailedSince = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $checksPendingSince = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $conflictingSince = null;

    #[ORM\Column(length: 64, nullable: true)]
    public ?string $approvalSha = null;

    /** When the newest approval that stands was submitted, which an agent review override compares with the time of the review. */
    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $latestApprovedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $latestApprovalId = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $latestApproverLogin = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $approvalId = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $approvalCheckRunId = null;

    /** The commit that the approval check run names. A new head has no run until Loupe posts one. */
    #[ORM\Column(length: 64, nullable: true)]
    public ?string $approvalCheckSha = null;

    #[ORM\Column(length: 32, nullable: true)]
    public ?string $approvalCheckConclusion = null;

    /** When Loupe last passed the approval check. A forge reading that started before it predates the check. */
    #[ORM\Column(type: MicrosecondDateTimeImmutableType::NAME, nullable: true, columnDefinition: 'TIMESTAMP(6) WITHOUT TIME ZONE DEFAULT NULL')]
    public ?\DateTimeImmutable $approvalCheckPassedAt = null;

    #[ORM\Column(length: 64, nullable: true)]
    public ?string $coveredSha = null;

    /** The last head that the forge found the approval does not cover, so it is not asked again. */
    #[ORM\Column(length: 64, nullable: true)]
    public ?string $uncoveredSha = null;

    /** A hash of the change of `changeFingerprintSha` against the base, with the line numbers left out. */
    #[ORM\Column(length: 64, nullable: true)]
    public ?string $changeFingerprint = null;

    /** A hash of the change of `changeFingerprintSha` against the base, with the line numbers kept. */
    #[ORM\Column(length: 64, nullable: true)]
    public ?string $linesFingerprint = null;

    /** The head the fingerprints belong to. Null fingerprints with a head mean the forge gave none for it. */
    #[ORM\Column(length: 64, nullable: true)]
    public ?string $changeFingerprintSha = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $defaultBranch = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    public array $headParents = [];

    /** The head Loupe asked the forge to bring up to date with its base. */
    #[ORM\Column(length: 64, nullable: true)]
    public ?string $syncFromSha = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $syncRequestedAt = null;

    /** The last head that a sync by Loupe produced. */
    #[ORM\Column(length: 64, nullable: true)]
    public ?string $syncedSha = null;

    /** The head of a merge by Loupe in flight. A read clears it when the head moves or the pull request leaves the open state. */
    #[ORM\Column(length: 64, nullable: true)]
    public ?string $mergeRequestedSha = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $mergeRequestedAt = null;

    /** The base of a base change by Loupe in flight. A read clears it when the base changes, matches, or the pull request leaves the open state. */
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $baseChangeRequestedTo = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $baseChangeRequestedAt = null;

    /** @var list<string> the newest forge ids of the reviews whose verdict went out, so a redelivered review is announced once */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    public array $announcedReviewIds = [];

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,

        /** The forge's slug, such as `github`. */
        #[ORM\Column(length: 50)]
        public readonly string $forge,

        #[ORM\Column(length: 255)]
        public string $repository,

        #[ORM\Column]
        public readonly int $number,
    ) {
        $this->repository = mb_strtolower($repository);
    }

    public function hasAnnouncedReview(string $reviewId): bool
    {
        return \in_array($reviewId, $this->announcedReviewIds, true);
    }

    public function recordAnnouncedReview(string $reviewId): void
    {
        $ids = array_values(array_diff($this->announcedReviewIds, [$reviewId]));
        $ids[] = $reviewId;
        $this->announcedReviewIds = \array_slice($ids, -self::ANNOUNCED_REVIEW_LIMIT);
    }

    public function apply(PullRequestSnapshot $snapshot): void
    {
        // Keyed on the review id, because GitHub moves the commit of a review onto a later merge from the base.
        $approvalChanged = $this->approvalId !== $snapshot->approvalId;
        $headMoved = $this->headSha !== $snapshot->headSha;
        if ($approvalChanged) {
            $this->coveredSha = $snapshot->approvalSha;
            $this->uncoveredSha = null;
        }
        // After the approval reset, so an approval of the head Loupe asked to update still follows the sync in one read.
        // The update merges the base into the head Loupe asked for, so the new head has exactly two parents and that head comes first.
        $syncMerge = 2 === \count($snapshot->headParents) && $snapshot->headParents[0] === $this->syncFromSha;
        if ($headMoved && null !== $this->syncFromSha && $syncMerge && $this->coveredSha === $this->syncFromSha) {
            // The merge commit of a sync from the covered head adds only base changes, so the approval still covers it.
            $this->coveredSha = $this->syncedSha = $snapshot->headSha;
        } elseif ($headMoved) {
            // A head that a person pushed is not a sync, even when it returns to a commit Loupe synced before.
            $this->syncedSha = null;
        }
        // A sync in flight keeps its marker through a new approval, so its merge commit is still recognised.
        if ($headMoved) {
            $this->syncFromSha = null;
            $this->syncRequestedAt = null;
        }
        $open = PullRequestState::Open === $snapshot->state;
        if ($headMoved || !$open) {
            $this->mergeRequestedSha = null;
            $this->mergeRequestedAt = null;
        }
        if (!$open || $snapshot->baseBranch !== $this->baseBranch || $snapshot->baseBranch === $this->baseChangeRequestedTo) {
            $this->baseChangeRequestedTo = null;
            $this->baseChangeRequestedAt = null;
        }
        $this->state = $snapshot->state;
        $this->draft = $snapshot->draft;
        $this->headSha = $snapshot->headSha;
        $this->headBranch = $snapshot->headBranch;
        $this->authorId = $snapshot->authorId;
        $this->authorLogin = $snapshot->authorLogin;
        $this->authorRead = true;
        $this->title = $snapshot->title;
        $this->baseBranch = $snapshot->baseBranch;
        $this->checks = $snapshot->checks;
        $this->checksSha = $snapshot->checksSha;
        $this->failedChecks = $snapshot->failedChecks;
        $this->mergeability = $snapshot->mergeability;
        $this->review = $snapshot->review;
        $this->readyToMerge = $snapshot->readyToMerge;
        $this->changesRequestedAt = $snapshot->changesRequestedAt;
        $this->openedAt = $snapshot->openedAt;
        $this->mergedAt = $snapshot->mergedAt;
        $this->closedAt = $snapshot->closedAt;
        $this->approvedAt = $snapshot->approvedAt;
        $this->approvalSha = $snapshot->approvalSha;
        $this->approvalId = $snapshot->approvalId;
        $this->latestApprovedAt = $snapshot->latestApprovedAt;
        $this->latestApprovalId = $snapshot->latestApprovalId;
        $this->latestApproverLogin = $snapshot->latestApproverLogin;
        $this->defaultBranch = $snapshot->defaultBranch;
        $this->headParents = $snapshot->headParents;
    }

    public function recordCoverage(ApprovalCoverage $coverage): void
    {
        match ($coverage) {
            ApprovalCoverage::Covered => $this->coveredSha = $this->headSha,
            ApprovalCoverage::NotCovered => $this->uncoveredSha = $this->headSha,
            ApprovalCoverage::Unknown => null,
        };
    }

    public function recordChangeFingerprint(string $headSha, ?PullRequestChangeFingerprint $fingerprint): void
    {
        $this->changeFingerprintSha = $headSha;
        $this->changeFingerprint = $fingerprint?->change;
        $this->linesFingerprint = $fingerprint?->lines;
    }

    public function approvalIsStale(): bool
    {
        return null !== $this->approvalId && $this->coveredSha !== $this->headSha;
    }

    /** A forge counts an approval of an older head, so a stale approval holds the merge. */
    public function settleReadyToMerge(bool $forgeReady, \DateTimeImmutable $now): void
    {
        $this->readyToMerge = $forgeReady && !$this->approvalIsStale();
        if (!$this->readyToMerge) {
            $this->readySince = null;
        } elseif (null === $this->readySince) {
            $this->readySince = $now;
        }
    }

    /** Stamps each problem on the read that first finds it, and clears it on the read that does not. Call it after the coverage is judged. */
    public function settleStartTimes(\DateTimeImmutable $now): void
    {
        $open = PullRequestState::Open === $this->state;
        $this->checksFailedSince = self::edge($open && PullRequestChecks::Failed === $this->checks, $this->checksFailedSince, $now);
        $this->checksPendingSince = self::edge($open && PullRequestChecks::Pending === $this->checks, $this->checksPendingSince, $now);
        $this->conflictingSince = self::edge($open && PullRequestMergeability::Conflicting === $this->mergeability, $this->conflictingSince, $now);
    }

    private static function edge(bool $holds, ?\DateTimeImmutable $since, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        return $holds ? ($since ?? $now) : null;
    }

    /**
     * The open time of the newest fix request that matches this pull request by forge, repository and number.
     *
     * @param list<array{forge: string, repository: string, number: int, openedAt: \DateTimeImmutable}> $fixes the done fix requests of the card
     */
    public function lastFixOpenedAt(array $fixes): ?\DateTimeImmutable
    {
        $newest = null;
        foreach ($fixes as $fix) {
            if ($fix['forge'] === $this->forge && $fix['number'] === $this->number && mb_strtolower($fix['repository']) === mb_strtolower($this->repository) && (null === $newest || $fix['openedAt'] > $newest)) {
                $newest = $fix['openedAt'];
            }
        }

        return $newest;
    }

    /**
     * Whether a request for changes waits for a fix run. GitHub keeps the request after a push, so only a fix run
     * that opened at or after the review answers it. A row with no review time reads as no open request.
     */
    public function changesRequestOpen(?\DateTimeImmutable $lastFixOpenedAt): bool
    {
        return PullRequestReview::ChangesRequested === $this->review
            && null !== $this->changesRequestedAt
            && (null === $lastFixOpenedAt || $lastFixOpenedAt < $this->changesRequestedAt);
    }

    public function snapshot(): PullRequestSnapshot
    {
        return new PullRequestSnapshot(
            $this->state,
            $this->draft,
            $this->headSha,
            $this->baseBranch,
            $this->checks,
            $this->checksSha,
            $this->failedChecks,
            $this->mergeability,
            $this->review,
            $this->readyToMerge,
            $this->changesRequestedAt,
            $this->openedAt,
            $this->mergedAt,
            $this->approvedAt,
            $this->approvalSha,
            $this->defaultBranch,
            $this->headParents,
            $this->approvalId,
            $this->coveredSha,
            $this->headBranch,
            $this->authorId,
            $this->authorLogin,
            $this->latestApprovedAt,
            $this->latestApprovalId,
            $this->latestApproverLogin,
            $this->title,
            $this->closedAt,
        );
    }
}
