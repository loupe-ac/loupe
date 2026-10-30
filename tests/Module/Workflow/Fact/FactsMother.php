<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Fact;

use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\AgentReview\Workflow\AgentReviewFactProvider;
use App\Module\AgentReview\Workflow\AgentReviewFacts;
use App\Module\AgentReview\Workflow\AgentReviewPostingFactProvider;
use App\Module\AgentReview\Workflow\AgentReviewPostingFacts;
use App\Module\AgentReview\Workflow\DocumentReviewConclusionFacts;
use App\Module\AgentReview\Workflow\DocumentReviewFactProvider;
use App\Module\AgentReview\Workflow\DocumentReviewFacts;
use App\Module\AgentReview\Workflow\ReviewedHead;
use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Board\Workflow\ArtifactReachFactProvider;
use App\Module\Board\Workflow\ArtifactReachFacts;
use App\Module\Board\Workflow\BlockerFactProvider;
use App\Module\Board\Workflow\BlockerFacts;
use App\Module\Board\Workflow\CardActivityFacts;
use App\Module\Board\Workflow\CardTypeFactProvider;
use App\Module\Board\Workflow\CardTypeFacts;
use App\Module\Board\Workflow\CardValueFactProvider;
use App\Module\Board\Workflow\CardValueFacts;
use App\Module\Board\Workflow\ChildActivityFacts;
use App\Module\Board\Workflow\ChildrenFactProvider;
use App\Module\Board\Workflow\ChildrenFacts;
use App\Module\Board\Workflow\ColumnArrivalFacts;
use App\Module\Board\Workflow\DocumentsFactProvider;
use App\Module\Board\Workflow\DocumentsFacts;
use App\Module\Board\Workflow\EpicBranchSyncFactProvider;
use App\Module\Board\Workflow\EpicBranchSyncFacts;
use App\Module\Board\Workflow\FixRunFacts;
use App\Module\Board\Workflow\FollowUpFacts;
use App\Module\Board\Workflow\HumanReviewFactProvider;
use App\Module\Board\Workflow\HumanReviewFacts;
use App\Module\Board\Workflow\ParentDocumentsFactProvider;
use App\Module\Board\Workflow\ParentDocumentsFacts;
use App\Module\Board\Workflow\ParentFactProvider;
use App\Module\Board\Workflow\ParentFacts;
use App\Module\Board\Workflow\PauseFacts;
use App\Module\Board\Workflow\PullRequestListFactProvider;
use App\Module\Board\Workflow\StaleApprovalFacts;
use App\Module\Board\Workflow\StuckDelayFacts;
use App\Module\Board\Workflow\WorkAtHeadFacts;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\Workflow\HoldFacts;
use App\Module\Bridge\Workflow\LatestWorkFactProvider;
use App\Module\Bridge\Workflow\LatestWorkFacts;
use App\Module\Bridge\Workflow\ParentWorkFactProvider;
use App\Module\Bridge\Workflow\ParentWorkFacts;
use App\Module\Bridge\Workflow\RefusalFactProvider;
use App\Module\Bridge\Workflow\RefusalFacts;
use App\Module\Bridge\Workflow\RunNeedsPersonFacts;
use App\Module\Bridge\Workflow\RunStoppedFacts;
use App\Module\Bridge\Workflow\SessionFacts;
use App\Module\Bridge\Workflow\WorkerRunFactProvider;
use App\Module\Bridge\Workflow\WorkerRunFacts;
use App\Module\Bridge\Workflow\WorkRequestFactProvider;
use App\Module\Bridge\Workflow\WorkRequestFacts;
use App\Module\Bridge\Workflow\WorkTakerFacts;
use App\Module\Inbox\Workflow\AgentReviewOpenFacts;
use App\Module\Inbox\Workflow\OpenQuestionFacts;
use App\Module\Inbox\Workflow\RunAskingFacts;
use App\Module\Inbox\Workflow\WaitingAskFacts;
use App\Module\Workflow\Contract\ChecksState;
use App\Module\Workflow\Contract\DocumentFacts;
use App\Module\Workflow\Contract\FactProvider;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\ForgeRequest;
use App\Module\Workflow\Contract\LegacyFingerprintGroup;
use App\Module\Workflow\Contract\PullRequestFacts;
use App\Module\Workflow\Contract\PullRequestList;
use App\Module\Workflow\Contract\PullRequestState;
use App\Module\Workflow\Contract\StackParent;
use App\Module\Workflow\Contract\Unreadable;
use App\Module\Workflow\Fact\BlockerHoldFacts;
use App\Module\Workflow\Fact\ScheduledRetryFacts;
use App\Module\Workflow\Fact\WorkHoldFacts;
use Symfony\Component\Uid\Uuid;

/** Builds facts for a neutral card. Each named argument overrides one default. */
final class FactsMother
{
    private const array PROVIDERS = [
        CardTypeFactProvider::class,
        CardValueFactProvider::class,
        ArtifactReachFactProvider::class,
        HumanReviewFactProvider::class,
        BlockerFactProvider::class,
        ParentFactProvider::class,
        EpicBranchSyncFactProvider::class,
        ChildrenFactProvider::class,
        DocumentsFactProvider::class,
        ParentDocumentsFactProvider::class,
        PullRequestListFactProvider::class,
        WorkRequestFactProvider::class,
        RefusalFactProvider::class,
        WorkerRunFactProvider::class,
        ParentWorkFactProvider::class,
    ];

    /**
     * The facts the providers of Board and Bridge give for the inputs. Their fingerprints come from the providers themselves.
     *
     * @param list<PullRequestFacts>                 $pullRequests
     * @param array<class-string, object|Unreadable> $provided     replaces what the inputs give, or adds a facts class
     * @param array<class-string, mixed>             $fingerprints
     */
    public static function facts(
        ?CardInputs $card = null,
        ?PullRequestFacts $pullRequest = null,
        array $pullRequests = [],
        ?RunInputs $run = null,
        \DateTimeImmutable $now = new \DateTimeImmutable('2026-10-01 12:00:00'),
        array $provided = [],
        array $fingerprints = [],
    ): Facts {
        $card ??= self::card();
        $run ??= self::run();
        $given = [
            ...self::byClass(new CardTypeFacts($card->type), new BlockerFacts($card->hasOpenBlocker, $card->hasClosedBlocker), new FollowUpFacts(false, false), new ParentFacts($card->isChild), new EpicBranchSyncFacts(true), new ArtifactReachFacts([]), new HumanReviewFacts(false), new CardValueFacts([])),
            ...self::byClass(new ChildrenFacts($card->childCount, $card->openChildCount, $card->childMergedIntoEpicBranch), new DocumentsFacts($card->documents), new ParentDocumentsFacts($card->parentDocuments)),
            ...self::byClass(new PullRequestList($pullRequests, $pullRequest)),
            ...self::byClass(new AgentReviewFacts(self::reviewedHeads($pullRequest, $pullRequests))),
            ...self::byClass(new AgentReviewPostingFacts(unposted: false, uncommented: [])),
            ...self::byClass(new DocumentReviewFacts([]), new DocumentReviewConclusionFacts([], [])),
            ...self::byClass(new WorkRequestFacts($run->activeWorkKinds, [], $run->activeWorkKinds, []), new RefusalFacts($run->lastRefusalCode), new WorkerRunFacts($run->activeWorkerKinds, [], $run->activeWorkerKinds, []), new ParentWorkFacts($run->parentActiveKinds)),
            ...self::byClass(new FixRunFacts([]), new StaleApprovalFacts([]), new WorkAtHeadFacts([], []), new LatestWorkFacts($run->latestWorkState, $run->latestWorkKind, $run->latestWorkReason)),
            ...self::byClass(new PauseFacts(null), new CardActivityFacts(null), new ColumnArrivalFacts(null), new ChildActivityFacts(null, false), new StuckDelayFacts(BoardAutomationSettings::DEFAULT_STUCK_DELAY_MINUTES), new BlockerHoldFacts(null), new WorkHoldFacts(null), new ScheduledRetryFacts([])),
            ...self::byClass(new RunStoppedFacts(null, null), new SessionFacts(null, null), new HoldFacts(null), new OpenQuestionFacts([]), new WaitingAskFacts([]), new WorkTakerFacts([])),
            ...self::byClass(new RunNeedsPersonFacts(null, null, null, null), new RunAskingFacts(false, null), new AgentReviewOpenFacts([])),
        ];
        $prints = [
            FixRunFacts::class => [],
            StaleApprovalFacts::class => [],
            WorkAtHeadFacts::class => [[], []],
            PauseFacts::class => null,
            CardActivityFacts::class => null,
            DocumentReviewConclusionFacts::class => [],
            ColumnArrivalFacts::class => null,
            ChildActivityFacts::class => [null, false],
            StuckDelayFacts::class => BoardAutomationSettings::DEFAULT_STUCK_DELAY_MINUTES,
            BlockerHoldFacts::class => null,
            ScheduledRetryFacts::class => null,
            WorkHoldFacts::class => false,
            RunStoppedFacts::class => null,
            SessionFacts::class => false,
            HoldFacts::class => false,
            OpenQuestionFacts::class => [],
            WaitingAskFacts::class => [],
            RunNeedsPersonFacts::class => null,
            RunAskingFacts::class => [false, null],
            AgentReviewOpenFacts::class => [],
            WorkTakerFacts::class => [],
            LatestWorkFacts::class => new \ReflectionClass(LatestWorkFactProvider::class)->newInstanceWithoutConstructor()->fingerprint($given[LatestWorkFacts::class]),
        ];
        $legacy = [];
        foreach (self::PROVIDERS as $providerClass) {
            $provider = new \ReflectionClass($providerClass)->newInstanceWithoutConstructor();
            \assert($provider instanceof FactProvider && $provider instanceof LegacyFingerprintGroup);
            $prints[$provider->factsClass()] = $provider->fingerprint($given[$provider->factsClass()]);
            $legacy[$provider->factsClass()] = $provider->legacyGroup();
        }

        $agentReview = [...$given, ...$provided][AgentReviewFacts::class];
        $documentReview = [...$given, ...$provided][DocumentReviewFacts::class];
        $prints[DocumentReviewFacts::class] = new \ReflectionClass(DocumentReviewFactProvider::class)->newInstanceWithoutConstructor()->fingerprint($documentReview);
        $prints[AgentReviewFacts::class] = new \ReflectionClass(AgentReviewFactProvider::class)->newInstanceWithoutConstructor()->fingerprint($agentReview);
        $prints[AgentReviewPostingFacts::class] = new \ReflectionClass(AgentReviewPostingFactProvider::class)->newInstanceWithoutConstructor()->fingerprint([...$given, ...$provided][AgentReviewPostingFacts::class]);

        return new Facts(
            now: $now,
            slot: $card->slot,
            parentSlot: $card->parentSlot,
            pullRequest: $pullRequest,
            provided: [...$given, ...$provided],
            fingerprints: [...$prints, ...$fingerprints],
            legacyGroups: $legacy,
        );
    }

    /**
     * Each pull request the facts name has a passing agent review, so the review holds no rule back.
     *
     * @param list<PullRequestFacts> $pullRequests
     *
     * @return list<ReviewedHead>
     */
    private static function reviewedHeads(?PullRequestFacts $pullRequest, array $pullRequests): array
    {
        return array_map(
            static fn (PullRequestFacts $facts): ReviewedHead => new ReviewedHead((string) $facts->id, 'reviewed-head', AgentReviewConclusion::Success),
            null === $pullRequest ? $pullRequests : [$pullRequest, ...$pullRequests],
        );
    }

    /**
     * @param list<DocumentFacts> $documents
     * @param list<DocumentFacts> $parentDocuments
     */
    public static function card(
        ?string $slot = null,
        string $type = 'feature',
        bool $hasOpenBlocker = false,
        bool $isChild = false,
        int $childCount = 0,
        int $openChildCount = 0,
        array $documents = [],
        bool $childMergedIntoEpicBranch = false,
        array $parentDocuments = [],
        ?string $parentSlot = null,
        bool $hasClosedBlocker = false,
    ): CardInputs {
        return new CardInputs($slot, $type, $hasOpenBlocker, $isChild, $childCount, $openChildCount, $documents, $childMergedIntoEpicBranch, $parentDocuments, $parentSlot, $hasClosedBlocker);
    }

    /** @param list<ForgeRequest> $forgeRequests */
    public static function pullRequest(
        PullRequestState $state = PullRequestState::Open,
        bool $draft = false,
        ChecksState $checks = ChecksState::None,
        bool $conflicting = false,
        bool $behind = false,
        int $approvalsCoveringHead = 0,
        bool $changesRequested = false,
        bool $baseIsMergeTarget = true,
        bool $baseIsEpicBranch = false,
        bool $stacked = false,
        bool $parentMerged = false,
        ?\DateTimeImmutable $closedAt = null,
        ?Uuid $id = null,
        ?string $headSha = null,
        ?int $number = null,
        ?StackParent $stackParent = null,
        bool $waitsForApproval = false,
        bool $readyToMerge = false,
        ?\DateTimeImmutable $readySince = null,
        ?\DateTimeImmutable $checksFailedSince = null,
        ?\DateTimeImmutable $checksPendingSince = null,
        ?\DateTimeImmutable $conflictingSince = null,
        array $forgeRequests = [],
        bool $checksOnHead = false,
        bool $canMerge = false,
        bool $baseIsDefault = false,
        bool $approvalCheckPassed = false,
        bool $canPostCheck = false,
        bool $mergeBlocked = false,
    ): PullRequestFacts {
        return new PullRequestFacts(
            state: $state,
            draft: $draft,
            checks: $checks,
            conflicting: $conflicting,
            behind: $behind,
            approvalsCoveringHead: $approvalsCoveringHead,
            changesRequested: $changesRequested,
            baseIsMergeTarget: $baseIsMergeTarget,
            baseIsEpicBranch: $baseIsEpicBranch,
            stacked: $stacked,
            parentMerged: $parentMerged,
            closedAt: $closedAt,
            id: $id,
            headSha: $headSha,
            number: $number,
            stackParent: $stackParent,
            waitsForApproval: $waitsForApproval,
            readyToMerge: $readyToMerge,
            readySince: $readySince,
            checksFailedSince: $checksFailedSince,
            checksPendingSince: $checksPendingSince,
            conflictingSince: $conflictingSince,
            forgeRequests: $forgeRequests,
            checksOnHead: $checksOnHead,
            canMerge: $canMerge,
            baseIsDefault: $baseIsDefault,
            approvalCheckPassed: $approvalCheckPassed,
            canPostCheck: $canPostCheck,
            mergeBlocked: $mergeBlocked,
        );
    }

    /**
     * @param list<string> $activeWorkKinds
     * @param list<string> $activeWorkerKinds
     * @param list<string> $parentActiveKinds
     */
    public static function run(
        array $activeWorkKinds = [],
        ?string $lastRefusalCode = null,
        array $activeWorkerKinds = [],
        array $parentActiveKinds = [],
        ?WorkRequestState $latestWorkState = null,
        ?string $latestWorkKind = null,
        ?string $latestWorkReason = null,
    ): RunInputs {
        return new RunInputs($activeWorkKinds, $lastRefusalCode, $activeWorkerKinds, $parentActiveKinds, $latestWorkState, $latestWorkKind, $latestWorkReason);
    }

    /** @return array<class-string, object> */
    private static function byClass(object ...$facts): array
    {
        $byClass = [];
        foreach ($facts as $one) {
            $byClass[$one::class] = $one;
        }

        return $byClass;
    }
}
