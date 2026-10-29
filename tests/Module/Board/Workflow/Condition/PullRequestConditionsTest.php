<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Workflow\Condition;

use App\Module\Board\Workflow\Condition\PullRequestApprovalCheckPassed;
use App\Module\Board\Workflow\Condition\PullRequestApprovalCoversHead;
use App\Module\Board\Workflow\Condition\PullRequestBaseIsDefault;
use App\Module\Board\Workflow\Condition\PullRequestBaseIsEpicBranch;
use App\Module\Board\Workflow\Condition\PullRequestBaseIsMergeTarget;
use App\Module\Board\Workflow\Condition\PullRequestBehind;
use App\Module\Board\Workflow\Condition\PullRequestCanMerge;
use App\Module\Board\Workflow\Condition\PullRequestCanPostCheck;
use App\Module\Board\Workflow\Condition\PullRequestChangesRequested;
use App\Module\Board\Workflow\Condition\PullRequestChecksFailed;
use App\Module\Board\Workflow\Condition\PullRequestChecksOnHead;
use App\Module\Board\Workflow\Condition\PullRequestChecksPassed;
use App\Module\Board\Workflow\Condition\PullRequestChecksPending;
use App\Module\Board\Workflow\Condition\PullRequestConflicting;
use App\Module\Board\Workflow\Condition\PullRequestDraft;
use App\Module\Board\Workflow\Condition\PullRequestDraftOpen;
use App\Module\Board\Workflow\Condition\PullRequestForgeRequestPending;
use App\Module\Board\Workflow\Condition\PullRequestLinked;
use App\Module\Board\Workflow\Condition\PullRequestMergeBlocked;
use App\Module\Board\Workflow\Condition\PullRequestOpen;
use App\Module\Board\Workflow\Condition\PullRequestParentMerged;
use App\Module\Board\Workflow\Condition\PullRequestReadyFor;
use App\Module\Board\Workflow\Condition\PullRequestsAllClosedUnmerged;
use App\Module\Board\Workflow\Condition\PullRequestsAllFinishedOneMerged;
use App\Module\Board\Workflow\Condition\PullRequestStacked;
use App\Module\Board\Workflow\Condition\PullRequestStackParent;
use App\Module\Workflow\Contract\ChecksState;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\EngineFact;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\PullRequestFacts;
use App\Module\Workflow\Contract\PullRequestList;
use App\Module\Workflow\Contract\PullRequestState;
use App\Tests\Module\Workflow\Fact\FactsMother;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PullRequestConditionsTest extends TestCase
{
    private const string NOW = '2026-10-01 12:00:00';

    /** @param array<string, mixed> $params */
    #[DataProvider('cases')]
    public function test_it_evaluates_the_facts(Condition $condition, array $params, Facts $facts, bool $expected): void
    {
        self::assertSame($expected, $condition->evaluate($facts, $params));
    }

    /** @return iterable<string, array{Condition, array<string, mixed>, Facts, bool}> */
    public static function cases(): iterable
    {
        yield 'open, open' => [new PullRequestOpen(), [], self::current(state: PullRequestState::Open), true];
        yield 'open, closed' => [new PullRequestOpen(), [], self::current(state: PullRequestState::Closed), false];
        yield 'open, merged' => [new PullRequestOpen(), [], self::current(state: PullRequestState::Merged), false];

        yield 'draft, open draft' => [new PullRequestDraft(), [], self::current(draft: true), true];
        yield 'draft, ready' => [new PullRequestDraft(), [], self::current(draft: false), false];
        yield 'draft, closed draft' => [new PullRequestDraft(), [], self::current(state: PullRequestState::Closed, draft: true), false];

        yield 'checks passed, passed' => [new PullRequestChecksPassed(), [], self::current(checks: ChecksState::Passed), true];
        yield 'checks passed, pending' => [new PullRequestChecksPassed(), [], self::current(checks: ChecksState::Pending), false];
        yield 'checks passed, none' => [new PullRequestChecksPassed(), [], self::current(checks: ChecksState::None), false];

        yield 'checks failed, failed' => [new PullRequestChecksFailed(), [], self::current(checks: ChecksState::Failed), true];
        yield 'checks failed, passed' => [new PullRequestChecksFailed(), [], self::current(checks: ChecksState::Passed), false];

        yield 'conflicting' => [new PullRequestConflicting(), [], self::current(conflicting: true), true];
        yield 'not conflicting' => [new PullRequestConflicting(), [], self::current(conflicting: false), false];

        yield 'behind' => [new PullRequestBehind(), [], self::current(behind: true), true];
        yield 'not behind' => [new PullRequestBehind(), [], self::current(behind: false), false];

        yield 'approvals, enough' => [new PullRequestApprovalCoversHead(), ['min' => 2], self::current(approvalsCoveringHead: 2), true];
        yield 'approvals, too few' => [new PullRequestApprovalCoversHead(), ['min' => 2], self::current(approvalsCoveringHead: 1), false];

        yield 'approvals by a person, a person alone' => [new PullRequestApprovalCoversHead(), ['min' => 1, 'by' => 'person'], self::current(approvalsCoveringHead: 1), true];

        yield 'approval check passed' => [new PullRequestApprovalCheckPassed(), [], self::current(approvalCheckPassed: true), true];
        yield 'approval check not passed' => [new PullRequestApprovalCheckPassed(), [], self::current(approvalCheckPassed: false), false];
        yield 'can post check' => [new PullRequestCanPostCheck(), [], self::current(canPostCheck: true), true];
        yield 'cannot post check' => [new PullRequestCanPostCheck(), [], self::current(canPostCheck: false), false];
        yield 'merge blocked' => [new PullRequestMergeBlocked(), [], self::current(mergeBlocked: true), true];
        yield 'merge not blocked' => [new PullRequestMergeBlocked(), [], self::current(mergeBlocked: false), false];

        yield 'changes requested' => [new PullRequestChangesRequested(), [], self::current(changesRequested: true), true];
        yield 'no changes requested' => [new PullRequestChangesRequested(), [], self::current(changesRequested: false), false];

        yield 'base is merge target' => [new PullRequestBaseIsMergeTarget(), [], self::current(baseIsMergeTarget: true), true];
        yield 'base is not merge target' => [new PullRequestBaseIsMergeTarget(), [], self::current(baseIsMergeTarget: false), false];

        yield 'checks on head' => [new PullRequestChecksOnHead(), [], self::current(checksOnHead: true), true];
        yield 'checks not on head' => [new PullRequestChecksOnHead(), [], self::current(checksOnHead: false), false];

        yield 'can merge' => [new PullRequestCanMerge(), [], self::current(canMerge: true), true];
        yield 'cannot merge' => [new PullRequestCanMerge(), [], self::current(canMerge: false), false];

        yield 'base is default' => [new PullRequestBaseIsDefault(), [], self::current(baseIsDefault: true), true];
        yield 'base is not default' => [new PullRequestBaseIsDefault(), [], self::current(baseIsDefault: false), false];

        yield 'base is epic branch' => [new PullRequestBaseIsEpicBranch(), [], self::current(baseIsEpicBranch: true), true];
        yield 'base is default branch' => [new PullRequestBaseIsEpicBranch(), [], self::current(baseIsEpicBranch: false), false];

        yield 'stacked' => [new PullRequestStacked(), [], self::current(stacked: true), true];
        yield 'not stacked' => [new PullRequestStacked(), [], self::current(stacked: false), false];

        yield 'parent merged' => [new PullRequestParentMerged(), [], self::current(stacked: true, parentMerged: true), true];
        yield 'parent open' => [new PullRequestParentMerged(), [], self::current(stacked: true, parentMerged: false), false];
        yield 'parent merged, not stacked' => [new PullRequestParentMerged(), [], self::current(stacked: false, parentMerged: true), false];

        $open = FactsMother::pullRequest(state: PullRequestState::Open);
        $merged = FactsMother::pullRequest(state: PullRequestState::Merged, closedAt: new \DateTimeImmutable('2026-10-01 11:00:00'));
        $closedAnHourAgo = FactsMother::pullRequest(state: PullRequestState::Closed, closedAt: new \DateTimeImmutable('2026-10-01 11:00:00'));

        yield 'all finished, one merged' => [new PullRequestsAllFinishedOneMerged(), [], self::all([$closedAnHourAgo, $merged]), true];
        yield 'all finished, one still open' => [new PullRequestsAllFinishedOneMerged(), [], self::all([$merged, $open]), false];
        yield 'all finished, none merged' => [new PullRequestsAllFinishedOneMerged(), [], self::all([$closedAnHourAgo]), false];
        yield 'all finished, none linked' => [new PullRequestsAllFinishedOneMerged(), [], self::all([]), false];

        yield 'all closed unmerged, one closed' => [new PullRequestsAllClosedUnmerged(), [], self::all([$closedAnHourAgo]), true];
        yield 'all closed unmerged, two closed' => [new PullRequestsAllClosedUnmerged(), [], self::all([$closedAnHourAgo, $closedAnHourAgo]), true];
        yield 'all closed unmerged, one merged' => [new PullRequestsAllClosedUnmerged(), [], self::all([$closedAnHourAgo, $merged]), false];
        yield 'all closed unmerged, one open' => [new PullRequestsAllClosedUnmerged(), [], self::all([$closedAnHourAgo, $open]), false];
        yield 'all closed unmerged, none linked' => [new PullRequestsAllClosedUnmerged(), [], self::all([]), false];

        yield 'linked, open' => [new PullRequestLinked(), [], self::all([$open]), true];
        yield 'linked, merged' => [new PullRequestLinked(), [], self::all([$merged]), true];
        yield 'linked, closed' => [new PullRequestLinked(), [], self::all([$closedAnHourAgo]), true];
        yield 'linked, none' => [new PullRequestLinked(), [], self::all([]), false];

        $draft = FactsMother::pullRequest(state: PullRequestState::Open, draft: true);
        $closedDraft = FactsMother::pullRequest(state: PullRequestState::Closed, draft: true, closedAt: new \DateTimeImmutable('2026-10-01 11:00:00'));
        yield 'draft open, a draft beside a ready one' => [new PullRequestDraftOpen(), [], self::all([$open, $draft]), true];
        yield 'draft open, ready only' => [new PullRequestDraftOpen(), [], self::all([$open]), false];
        yield 'draft open, a closed draft' => [new PullRequestDraftOpen(), [], self::all([$open, $closedDraft]), false];
        yield 'draft open, none' => [new PullRequestDraftOpen(), [], self::all([]), false];
    }

    /** @param array<string, mixed> $params */
    #[DataProvider('singlePullRequestConditions')]
    public function test_a_single_pull_request_condition_is_false_with_no_current_pull_request(Condition $condition, array $params): void
    {
        $facts = FactsMother::facts(pullRequest: null, pullRequests: [FactsMother::pullRequest(
            draft: true,
            checks: ChecksState::Passed,
            conflicting: true,
            behind: true,
            approvalsCoveringHead: 5,
            changesRequested: true,
            baseIsMergeTarget: true,
            baseIsEpicBranch: true,
            stacked: true,
            parentMerged: true,
        )]);

        self::assertFalse($condition->evaluate($facts, $params));
    }

    /** @return iterable<string, array{Condition, array<string, mixed>}> */
    public static function singlePullRequestConditions(): iterable
    {
        yield 'pr.open' => [new PullRequestOpen(), []];
        yield 'pr.draft' => [new PullRequestDraft(), []];
        yield 'pr.checks_passed' => [new PullRequestChecksPassed(), []];
        yield 'pr.checks_failed' => [new PullRequestChecksFailed(), []];
        yield 'pr.conflicting' => [new PullRequestConflicting(), []];
        yield 'pr.behind' => [new PullRequestBehind(), []];
        yield 'pr.approval_covers_head' => [new PullRequestApprovalCoversHead(), ['min' => 1]];
        yield 'pr.changes_requested' => [new PullRequestChangesRequested(), []];
        yield 'pr.base_is_merge_target' => [new PullRequestBaseIsMergeTarget(), []];
        yield 'pr.checks_on_head' => [new PullRequestChecksOnHead(), []];
        yield 'pr.approval_check_passed' => [new PullRequestApprovalCheckPassed(), []];
        yield 'pr.can_post_check' => [new PullRequestCanPostCheck(), []];
        yield 'pr.merge_blocked' => [new PullRequestMergeBlocked(), []];
        yield 'pr.can_merge' => [new PullRequestCanMerge(), []];
        yield 'pr.base_is_default' => [new PullRequestBaseIsDefault(), []];
        yield 'pr.base_is_epic_branch' => [new PullRequestBaseIsEpicBranch(), []];
        yield 'pr.stacked' => [new PullRequestStacked(), []];
        yield 'pr.parent_merged' => [new PullRequestParentMerged(), []];
        yield 'pr.checks_pending' => [new PullRequestChecksPending(), []];
        yield 'pr.forge_request_pending' => [new PullRequestForgeRequestPending(), []];
        yield 'pr.stack_parent' => [new PullRequestStackParent(), []];
        yield 'pr.ready_for' => [new PullRequestReadyFor(), ['delay' => 1]];
    }

    /**
     * @param array<string, mixed>          $params
     * @param array<string, string>         $expectedParameters
     * @param list<EngineFact|class-string> $expectedReads
     */
    #[DataProvider('waiting')]
    public function test_it_says_what_it_waits_for_and_what_it_reads(Condition $condition, array $params, string $expectedKey, array $expectedParameters, array $expectedReads): void
    {
        $message = $condition->waitingFor($params);

        self::assertSame('workflow.waiting.'.$expectedKey, $message->getMessage());
        self::assertSame($expectedParameters, $message->getParameters());

        $negated = $condition->waitingFor($params, negated: true);

        self::assertSame('workflow.waiting.not.'.$expectedKey, $negated->getMessage());
        self::assertSame($expectedParameters, $negated->getParameters());
        self::assertSame($expectedReads, $condition->reads($params));
    }

    /** @return iterable<string, array{Condition, array<string, mixed>, string, array<string, string>, list<EngineFact|class-string>}> */
    public static function waiting(): iterable
    {
        $one = [EngineFact::PullRequest];
        yield 'pr.open' => [new PullRequestOpen(), [], 'pr_open', [], [EngineFact::PullRequestSubject]];
        yield 'pr.draft' => [new PullRequestDraft(), [], 'pr_draft', [], $one];
        yield 'pr.checks_passed' => [new PullRequestChecksPassed(), [], 'pr_checks_passed', [], $one];
        yield 'pr.checks_failed' => [new PullRequestChecksFailed(), [], 'pr_checks_failed', [], $one];
        yield 'pr.conflicting' => [new PullRequestConflicting(), [], 'pr_conflicting', [], $one];
        yield 'pr.behind' => [new PullRequestBehind(), [], 'pr_behind', [], [EngineFact::PullRequest, EngineFact::PullRequestHead]];
        yield 'pr.approval_check_passed' => [new PullRequestApprovalCheckPassed(), [], 'pr_approval_check_passed', [], [EngineFact::PullRequest, EngineFact::PullRequestApprovalCheck]];
        yield 'pr.can_post_check' => [new PullRequestCanPostCheck(), [], 'pr_can_post_check', [], [EngineFact::PullRequest, EngineFact::PullRequestApprovalCheck]];
        yield 'pr.merge_blocked' => [new PullRequestMergeBlocked(), [], 'pr_merge_blocked', [], [EngineFact::PullRequest, EngineFact::PullRequestMergeGate]];
        yield 'pr.approval_covers_head' => [new PullRequestApprovalCoversHead(), ['min' => 2], 'pr_approval_covers_head', ['%min%' => '2'], $one];
        yield 'pr.changes_requested' => [new PullRequestChangesRequested(), [], 'pr_changes_requested', [], $one];
        yield 'pr.base_is_merge_target' => [new PullRequestBaseIsMergeTarget(), [], 'pr_base_is_merge_target', [], $one];
        yield 'pr.checks_on_head' => [new PullRequestChecksOnHead(), [], 'pr_checks_on_head', [], [EngineFact::PullRequest, EngineFact::PullRequestMergeGate]];
        yield 'pr.can_merge' => [new PullRequestCanMerge(), [], 'pr_can_merge', [], [EngineFact::PullRequest, EngineFact::PullRequestMergeGate]];
        yield 'pr.base_is_default' => [new PullRequestBaseIsDefault(), [], 'pr_base_is_default', [], [EngineFact::PullRequest, EngineFact::PullRequestMergeGate]];
        yield 'pr.base_is_epic_branch' => [new PullRequestBaseIsEpicBranch(), [], 'pr_base_is_epic_branch', [], $one];
        yield 'pr.stacked' => [new PullRequestStacked(), [], 'pr_stacked', [], $one];
        yield 'pr.parent_merged' => [new PullRequestParentMerged(), [], 'pr_parent_merged', [], $one];
        yield 'pr.checks_pending' => [new PullRequestChecksPending(), [], 'pr_checks_pending', [], $one];
        yield 'pr.forge_request_pending' => [new PullRequestForgeRequestPending(), [], 'pr_forge_request_pending', [], $one];
        yield 'pr.stack_parent' => [new PullRequestStackParent(), [], 'pr_stack_parent', [], $one];
        yield 'pr.stack_parent, known' => [new PullRequestStackParent(), ['parent' => 'known'], 'pr_stack_parent_known', [], $one];
        yield 'pr.stack_parent, unknown' => [new PullRequestStackParent(), ['parent' => 'unknown'], 'pr_stack_parent_unknown', [], $one];
        yield 'pr.ready_for' => [new PullRequestReadyFor(), ['delay' => 30], 'pr_ready_for', ['%delay%' => '30'], $one];
        yield 'card.pr.linked' => [new PullRequestLinked(), [], 'pr_linked', [], [PullRequestList::class]];
        yield 'card.pr.draft_open' => [new PullRequestDraftOpen(), [], 'pr_draft_open', [], [PullRequestList::class]];
        yield 'card.pr.all_closed_unmerged' => [new PullRequestsAllClosedUnmerged(), [], 'pr_all_closed_unmerged', [], [PullRequestList::class]];
        yield 'card.pr.all_finished_one_merged' => [new PullRequestsAllFinishedOneMerged(), [], 'pr_all_finished_one_merged', [], [PullRequestList::class]];
    }

    private static function current(
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
        bool $checksOnHead = false,
        bool $canMerge = false,
        bool $baseIsDefault = false,
        bool $approvalCheckPassed = false,
        bool $canPostCheck = false,
        bool $mergeBlocked = false,
    ): Facts {
        $pullRequest = FactsMother::pullRequest(
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
            checksOnHead: $checksOnHead,
            canMerge: $canMerge,
            baseIsDefault: $baseIsDefault,
            approvalCheckPassed: $approvalCheckPassed,
            canPostCheck: $canPostCheck,
            mergeBlocked: $mergeBlocked,
        );

        return FactsMother::facts(pullRequest: $pullRequest, pullRequests: [$pullRequest], now: new \DateTimeImmutable(self::NOW));
    }

    /** @param list<PullRequestFacts> $pullRequests */
    private static function all(array $pullRequests): Facts
    {
        return FactsMother::facts(pullRequest: $pullRequests[0] ?? null, pullRequests: $pullRequests, now: new \DateTimeImmutable(self::NOW));
    }
}
