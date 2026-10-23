<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Workflow\Condition;

use App\Module\Board\Workflow\Condition\PullRequestChecksFailed;
use App\Module\Board\Workflow\Condition\PullRequestChecksPending;
use App\Module\Board\Workflow\Condition\PullRequestConflicting;
use App\Module\Board\Workflow\Condition\PullRequestForgeRequestPending;
use App\Module\Board\Workflow\Condition\PullRequestOpen;
use App\Module\Board\Workflow\Condition\PullRequestReadyFor;
use App\Module\Board\Workflow\Condition\PullRequestStackParent;
use App\Module\Workflow\Contract\ChecksState;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Evidence;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\ForgeRequest;
use App\Module\Workflow\Contract\MarkerEvidence;
use App\Module\Workflow\Contract\MarkerSubject;
use App\Module\Workflow\Contract\MarkerSubjectType;
use App\Module\Workflow\Contract\PullRequestFacts;
use App\Module\Workflow\Contract\PullRequestState;
use App\Module\Workflow\Contract\StackParent;
use App\Module\Workflow\Contract\TimedCondition;
use App\Tests\Module\Workflow\Fact\FactsMother;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class PullRequestMarkerConditionsTest extends TestCase
{
    private const string NOW = '2026-10-01 12:00:00';

    /** @param array<string, mixed> $params */
    #[DataProvider('cases')]
    public function test_it_evaluates_the_facts(Condition $condition, array $params, PullRequestFacts $pullRequest, bool $expected): void
    {
        self::assertSame($expected, $condition->evaluate(self::facts($pullRequest), $params));
    }

    /** @return iterable<string, array{Condition, array<string, mixed>, PullRequestFacts, bool}> */
    public static function cases(): iterable
    {
        yield 'checks pending' => [new PullRequestChecksPending(), [], FactsMother::pullRequest(checks: ChecksState::Pending), true];
        yield 'checks pending, passed' => [new PullRequestChecksPending(), [], FactsMother::pullRequest(checks: ChecksState::Passed), false];
        yield 'checks pending, closed' => [new PullRequestChecksPending(), [], FactsMother::pullRequest(state: PullRequestState::Closed, checks: ChecksState::Pending), false];

        yield 'forge request pending' => [new PullRequestForgeRequestPending(), [], FactsMother::pullRequest(forgeRequests: [self::request('11:55', '12:05')]), true];
        yield 'forge request expired' => [new PullRequestForgeRequestPending(), [], FactsMother::pullRequest(forgeRequests: [self::request('11:50', '12:00')]), false];
        yield 'no forge request' => [new PullRequestForgeRequestPending(), [], FactsMother::pullRequest(), false];

        $known = new StackParent('parent-branch', 40, 12);
        $branch = new StackParent('parent-branch');
        $numberOnly = new StackParent('parent-branch', 40);
        yield 'stack parent, known' => [new PullRequestStackParent(), ['parent' => 'known'], self::ready(stackParent: $known), true];
        yield 'stack parent, known asked, branch only' => [new PullRequestStackParent(), ['parent' => 'known'], self::ready(stackParent: $branch), false];
        yield 'stack parent, unknown, branch only' => [new PullRequestStackParent(), ['parent' => 'unknown'], self::ready(stackParent: $branch), true];
        yield 'stack parent, unknown, no card links the parent' => [new PullRequestStackParent(), ['parent' => 'unknown'], self::ready(stackParent: $numberOnly), true];
        yield 'stack parent, unknown asked, known' => [new PullRequestStackParent(), ['parent' => 'unknown'], self::ready(stackParent: $known), false];
        yield 'stack parent, any' => [new PullRequestStackParent(), [], self::ready(stackParent: $branch), true];
        yield 'stack parent, not stacked' => [new PullRequestStackParent(), [], self::ready(), false];
        yield 'stack parent, not ready' => [new PullRequestStackParent(), [], self::ready(stackParent: $known, readyToMerge: false), false];
        yield 'stack parent, waits for approval' => [new PullRequestStackParent(), [], self::ready(stackParent: $known, waitsForApproval: true), false];
        yield 'stack parent, request pending' => [new PullRequestStackParent(), [], self::ready(stackParent: $known, forgeRequests: [self::request('11:55', '12:05')]), false];

        yield 'ready for, delay passed' => [new PullRequestReadyFor(), ['delay' => 60], self::ready(), true];
        yield 'ready for, delay just passed' => [new PullRequestReadyFor(), ['delay' => 120], self::ready(), true];
        yield 'ready for, delay not passed' => [new PullRequestReadyFor(), ['delay' => 121], self::ready(), false];
        yield 'ready for, not ready' => [new PullRequestReadyFor(), ['delay' => 1], self::ready(readyToMerge: false), false];
        yield 'ready for, no ready time' => [new PullRequestReadyFor(), ['delay' => 1], self::ready(readySince: null), false];
        yield 'ready for, waits for approval' => [new PullRequestReadyFor(), ['delay' => 1], self::ready(waitsForApproval: true), false];
        yield 'ready for, merge in flight' => [new PullRequestReadyFor(), ['delay' => 1], self::ready(forgeRequests: [self::request('11:55', '12:05', merge: true)]), false];
        yield 'ready for, merge expired' => [new PullRequestReadyFor(), ['delay' => 1], self::ready(forgeRequests: [self::request('11:40', '11:50', merge: true)]), true];
        yield 'ready for, stacked' => [new PullRequestReadyFor(), ['delay' => 1], self::ready(stackParent: new StackParent('parent-branch')), false];
        yield 'ready for, merged' => [new PullRequestReadyFor(), ['delay' => 1], self::ready(state: PullRequestState::Merged), false];
    }

    /** @param array<string, mixed> $params */
    #[DataProvider('conditionsWithParams')]
    public function test_it_is_false_and_gives_no_evidence_with_no_current_pull_request(Condition&MarkerEvidence $condition, array $params): void
    {
        $facts = FactsMother::facts(pullRequest: null, now: new \DateTimeImmutable(self::NOW));

        self::assertFalse($condition->evaluate($facts, $params));
        self::assertNull($condition->evidence($facts, $params));
    }

    /** @return iterable<string, array{Condition&MarkerEvidence, array<string, mixed>}> */
    public static function conditionsWithParams(): iterable
    {
        yield 'pr.checks_failed' => [new PullRequestChecksFailed(), []];
        yield 'pr.checks_pending' => [new PullRequestChecksPending(), []];
        yield 'pr.conflicting' => [new PullRequestConflicting(), []];
        yield 'pr.forge_request_pending' => [new PullRequestForgeRequestPending(), []];
        yield 'pr.stack_parent' => [new PullRequestStackParent(), []];
        yield 'pr.ready_for' => [new PullRequestReadyFor(), ['delay' => 5]];
    }

    /** @param array<string, mixed> $params */
    #[DataProvider('evidence')]
    public function test_it_gives_the_label_values(MarkerEvidence $condition, array $params, PullRequestFacts $pullRequest, Evidence $expected): void
    {
        self::assertEquals($expected, $condition->evidence(self::facts($pullRequest), $params));
    }

    /** @return iterable<string, array{MarkerEvidence, array<string, mixed>, PullRequestFacts, Evidence}> */
    public static function evidence(): iterable
    {
        $number = ['%number%' => 7];

        yield 'checks failed' => [new PullRequestChecksFailed(), [], FactsMother::pullRequest(number: 7), new Evidence($number)];
        yield 'checks pending' => [new PullRequestChecksPending(), [], FactsMother::pullRequest(number: 7), new Evidence($number)];
        yield 'conflicting' => [new PullRequestConflicting(), [], FactsMother::pullRequest(number: 7), new Evidence($number)];
        yield 'no number' => [new PullRequestChecksFailed(), [], FactsMother::pullRequest(), new Evidence()];
        yield 'forge request pending' => [new PullRequestForgeRequestPending(), [], FactsMother::pullRequest(number: 7, forgeRequests: [self::request('11:55', '12:05')]), new Evidence($number)];

        yield 'stack parent, known' => [
            new PullRequestStackParent(),
            ['parent' => 'known'],
            self::ready(stackParent: new StackParent('parent-branch', 40, 12)),
            new Evidence([...$number, '%parent_number%' => 12, '%pull_request%' => 40]),
        ];
        yield 'stack parent, branch' => [
            new PullRequestStackParent(),
            ['parent' => 'unknown'],
            self::ready(stackParent: new StackParent('parent-branch', 40)),
            new Evidence([...$number, '%branch%' => 'parent-branch']),
        ];
        yield 'ready for' => [new PullRequestReadyFor(), ['delay' => 30], self::ready(), new Evidence($number)];
    }

    public function test_a_pull_request_with_a_row_and_a_head_commit_names_itself_as_the_thing(): void
    {
        $id = Uuid::v7();
        $pullRequest = FactsMother::pullRequest(id: $id, headSha: 'abc123', number: 7);

        self::assertEquals(
            new Evidence(['%number%' => 7], new MarkerSubject(MarkerSubjectType::PullRequest, ['pullRequest' => $id->toRfc4122(), 'head' => 'abc123'])),
            new PullRequestOpen()->evidence(self::facts($pullRequest), []),
        );
        self::assertSame(MarkerSubjectType::PullRequest, new PullRequestOpen()->subjectType());
    }

    public function test_a_pull_request_without_a_head_commit_names_no_thing(): void
    {
        self::assertNull(new PullRequestOpen()->evidence(self::facts(FactsMother::pullRequest(id: Uuid::v7(), number: 7)), [])?->subject);
    }

    /** @param array<string, mixed> $params */
    #[DataProvider('changes')]
    public function test_it_says_when_the_clock_alone_changes_the_answer(TimedCondition $condition, array $params, PullRequestFacts $pullRequest, ?string $expected): void
    {
        self::assertEquals(null === $expected ? null : new \DateTimeImmutable($expected), $condition->changesAt(self::facts($pullRequest), $params));
    }

    /** @return iterable<string, array{TimedCondition, array<string, mixed>, PullRequestFacts, ?string}> */
    public static function changes(): iterable
    {
        yield 'ready for, delay ends later' => [new PullRequestReadyFor(), ['delay' => 180], self::ready(), '2026-10-01 13:00:00'];
        yield 'ready for, delay ended' => [new PullRequestReadyFor(), ['delay' => 60], self::ready(), null];
        yield 'ready for, not ready' => [new PullRequestReadyFor(), ['delay' => 180], self::ready(readyToMerge: false, readySince: null), null];
        yield 'ready for, a merge ends first' => [new PullRequestReadyFor(), ['delay' => 180], self::ready(forgeRequests: [self::request('11:55', '12:05', merge: true)]), '2026-10-01 12:05:00'];
        yield 'ready for, the delay ends first' => [new PullRequestReadyFor(), ['delay' => 122], self::ready(forgeRequests: [self::request('11:55', '12:05', merge: true)]), '2026-10-01 12:02:00'];
        yield 'ready for, no pull request' => [new PullRequestReadyFor(), ['delay' => 180], FactsMother::pullRequest(state: PullRequestState::Closed), null];

        yield 'forge request, the first one ends' => [new PullRequestForgeRequestPending(), [], FactsMother::pullRequest(forgeRequests: [self::request('11:58', '12:08'), self::request('11:55', '12:05')]), '2026-10-01 12:05:00'];
        yield 'forge request, none pending' => [new PullRequestForgeRequestPending(), [], FactsMother::pullRequest(forgeRequests: [self::request('11:40', '11:50')]), null];

        yield 'stack parent, a request ends' => [new PullRequestStackParent(), [], self::ready(stackParent: new StackParent('b'), forgeRequests: [self::request('11:55', '12:05')]), '2026-10-01 12:05:00'];
        yield 'stack parent, nothing pending' => [new PullRequestStackParent(), [], self::ready(stackParent: new StackParent('b')), null];
    }

    /** @param list<ForgeRequest> $forgeRequests */
    private static function ready(
        ?StackParent $stackParent = null,
        bool $readyToMerge = true,
        ?\DateTimeImmutable $readySince = new \DateTimeImmutable('2026-10-01 10:00:00'),
        bool $waitsForApproval = false,
        array $forgeRequests = [],
        PullRequestState $state = PullRequestState::Open,
    ): PullRequestFacts {
        return FactsMother::pullRequest(
            state: $state,
            number: 7,
            stackParent: $stackParent,
            waitsForApproval: $waitsForApproval,
            readyToMerge: $readyToMerge,
            readySince: $readySince,
            forgeRequests: $forgeRequests,
        );
    }

    private static function request(string $at, string $expires, bool $merge = false): ForgeRequest
    {
        return new ForgeRequest(new \DateTimeImmutable('2026-10-01 '.$at), new \DateTimeImmutable('2026-10-01 '.$expires), $merge);
    }

    private static function facts(PullRequestFacts $pullRequest): Facts
    {
        return FactsMother::facts(pullRequest: $pullRequest, pullRequests: [$pullRequest], now: new \DateTimeImmutable(self::NOW));
    }
}
