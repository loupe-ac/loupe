<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

final readonly class PullRequestFacts
{
    public const string REASON_CONFLICT = 'conflict';
    public const string REASON_CHECKS_FAILED = 'checks-failed';
    public const string REASON_CHANGES_REQUESTED = 'changes-requested';

    /** Every reason `fixReason()` can answer. */
    public const array FIX_REASONS = [self::REASON_CONFLICT, self::REASON_CHECKS_FAILED, self::REASON_CHANGES_REQUESTED];

    /**
     * @param bool               $baseIsMergeTarget     the base is the default branch or the branch of the card's epic
     * @param bool               $baseIsEpicBranch      the base is the branch of the card's epic
     * @param ?Uuid              $id                    the id of the Forge pull request row
     * @param ?string            $headSha               the head commit, read through EngineFact::PullRequestHead and never part of the shared fingerprint
     * @param ?StackParent       $stackParent           null when the base is the default branch or the epic branch, or every pull request of the base finished
     * @param bool               $waitsForApproval      open, ready for review, checks passed, based on the default branch, and no approval covers the head
     * @param bool               $checksOnHead          the checks ran on the head commit, read through EngineFact::PullRequestMergeGate
     * @param bool               $canMerge              the forge reads the pull request as mergeable, behind or blocked, read through EngineFact::PullRequestMergeGate
     * @param bool               $baseIsDefault         the base is the default branch of the repository, read through EngineFact::PullRequestMergeGate
     * @param list<ForgeRequest> $forgeRequests         the requests to the forge that the forge has not answered, expired ones included
     * @param bool               $approvalCheckPassed   Loupe posted the approval check with the conclusion success on the head commit, read through EngineFact::PullRequestApprovalCheck
     * @param bool               $canPostCheck          the repository has an active installation, so Loupe can post a check run, read through EngineFact::PullRequestApprovalCheck
     * @param bool               $mergeBlocked          the forge reads the merge as blocked, read through EngineFact::PullRequestMergeGate
     * @param bool               $baseRequiresReview    the ruleset of the default branch requires an approving review, and the pull request is open on that branch, read through EngineFact::PullRequestRuleset
     * @param bool               $approvalCheckUnpinned the ruleset of the default branch requires the approval check with no source, and the pull request is open on that branch, read through EngineFact::PullRequestRuleset
     */
    public function __construct(
        public PullRequestState $state,
        public bool $draft,
        public ChecksState $checks,
        public bool $conflicting,
        public bool $behind,
        public int $approvalsCoveringHead,
        public bool $changesRequested,
        public bool $baseIsMergeTarget,
        public bool $baseIsEpicBranch,
        public bool $stacked,
        public bool $parentMerged,
        public ?\DateTimeImmutable $closedAt,
        public ?Uuid $id = null,
        public ?string $headSha = null,
        public ?int $number = null,
        public ?StackParent $stackParent = null,
        public bool $waitsForApproval = false,
        public bool $readyToMerge = false,
        public ?\DateTimeImmutable $readySince = null,
        public ?\DateTimeImmutable $checksFailedSince = null,
        public ?\DateTimeImmutable $checksPendingSince = null,
        public ?\DateTimeImmutable $conflictingSince = null,
        public array $forgeRequests = [],
        public bool $checksOnHead = false,
        public bool $canMerge = false,
        public bool $baseIsDefault = false,
        public bool $approvalCheckPassed = false,
        public bool $canPostCheck = false,
        public bool $mergeBlocked = false,
        public bool $baseRequiresReview = false,
        public bool $approvalCheckUnpinned = false,
    ) {
    }

    public function mergeInFlight(\DateTimeImmutable $now): bool
    {
        return array_any($this->forgeRequests, static fn (ForgeRequest $request): bool => $request->merge && $request->pendingAt($now));
    }

    /** The oldest request the forge has not answered, or null when none is pending. */
    public function forgeRequestedAt(\DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $pending = array_map(static fn (ForgeRequest $request): \DateTimeImmutable => $request->requestedAt, $this->pendingRequests($now));

        return [] === $pending ? null : min($pending);
    }

    /** When the first pending request expires, or null when none is pending. */
    public function firstForgeRequestExpiresAt(\DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $pending = array_map(static fn (ForgeRequest $request): \DateTimeImmutable => $request->expiresAt, $this->pendingRequests($now));

        return [] === $pending ? null : min($pending);
    }

    /** Open and ready to merge, with no approval missing and no request to the forge pending. */
    public function readyWithNothingPending(\DateTimeImmutable $now): bool
    {
        return PullRequestState::Open === $this->state
            && $this->readyToMerge
            && null !== $this->readySince
            && !$this->waitsForApproval
            && null === $this->forgeRequestedAt($now);
    }

    /** @return list<ForgeRequest> */
    private function pendingRequests(\DateTimeImmutable $now): array
    {
        return array_values(array_filter($this->forgeRequests, static fn (ForgeRequest $request): bool => $request->pendingAt($now)));
    }

    /** What a fix of the pull request answers, most pressing first. Null when nothing needs a fix. */
    public function fixReason(): ?string
    {
        return match (true) {
            $this->conflicting => self::REASON_CONFLICT,
            ChecksState::Failed === $this->checks => self::REASON_CHECKS_FAILED,
            $this->changesRequested => self::REASON_CHANGES_REQUESTED,
            default => null,
        };
    }

    /**
     * The part of the pull request whose change counts as a change. The close time stays out: a closed pull request
     * takes it from its last read, so a re-read changes it.
     *
     * @return list<mixed>
     */
    public function fingerprint(): array
    {
        return [
            $this->state->value,
            $this->draft,
            $this->checks->value,
            $this->conflicting,
            $this->behind,
            $this->approvalsCoveringHead,
            $this->changesRequested,
            $this->baseIsMergeTarget,
            $this->baseIsEpicBranch,
            $this->stacked,
            $this->parentMerged,
        ];
    }
}
