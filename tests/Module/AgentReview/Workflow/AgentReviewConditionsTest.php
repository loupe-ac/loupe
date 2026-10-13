<?php

declare(strict_types=1);

namespace App\Tests\Module\AgentReview\Workflow;

use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\AgentReview\Workflow\AgentReviewFacts;
use App\Module\AgentReview\Workflow\Condition\AgentReviewDue;
use App\Module\AgentReview\Workflow\Condition\AgentReviewFailed;
use App\Module\AgentReview\Workflow\Condition\AgentReviewOverridden;
use App\Module\AgentReview\Workflow\Condition\AgentReviewPassed;
use App\Module\AgentReview\Workflow\Condition\AgentReviewUncommented;
use App\Module\AgentReview\Workflow\Condition\AgentReviewUnposted;
use App\Module\AgentReview\Workflow\ReviewedHead;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\EngineFact;
use App\Module\Workflow\Contract\Facts;
use App\Tests\Module\Workflow\Fact\FactsMother;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class AgentReviewConditionsTest extends TestCase
{
    private const string BOUND = '01a1218c-0000-7000-8000-000000000001';
    private const string OTHER = '01a1218c-0000-7000-8000-000000000002';

    /** @return iterable<string, array{Condition, AgentReviewFacts, bool}> */
    public static function cases(): iterable
    {
        $none = self::head(null);
        $success = self::head(AgentReviewConclusion::Success);
        $failure = self::head(AgentReviewConclusion::Failure);
        $headless = new ReviewedHead(self::BOUND, '', null);

        yield 'due: epic' => [new AgentReviewDue(), self::review([$none], epic: true), false];
        yield 'due: no pull request head' => [new AgentReviewDue(), self::review([]), false];
        yield 'due: bound head without review' => [new AgentReviewDue(), self::review([self::head(AgentReviewConclusion::Success, self::OTHER), $none]), true];
        yield 'due: bound head reviewed, another head without review' => [new AgentReviewDue(), self::review([$success, self::head(null, self::OTHER)]), false];
        yield 'due: every head reviewed' => [new AgentReviewDue(), self::review([$success, $failure]), false];

        $answered = self::head(AgentReviewConclusion::Failure, answered: true);
        yield 'due: failed review answered by a fix round' => [new AgentReviewDue(), self::review([$answered]), true];
        yield 'due: failed review answered on another head only' => [new AgentReviewDue(), self::review([$failure, self::head(AgentReviewConclusion::Failure, self::OTHER, true)]), false];
        yield 'due: epic with an answered review' => [new AgentReviewDue(), self::review([$answered], epic: true), false];
        yield 'failed: failed review answered by a fix round' => [new AgentReviewFailed(), self::review([$answered]), false];
        yield 'passed: failed review answered by a fix round' => [new AgentReviewPassed(), self::review([$answered]), false];
        yield 'failed: head without review' => [new AgentReviewFailed(), self::review([$none]), false];
        yield 'failed: every head passed' => [new AgentReviewFailed(), self::review([$success]), false];
        yield 'failed: bound head failed' => [new AgentReviewFailed(), self::review([self::head(AgentReviewConclusion::Success, self::OTHER), $failure]), true];
        yield 'failed: bound head passed, another head failed' => [new AgentReviewFailed(), self::review([$success, self::head(AgentReviewConclusion::Failure, self::OTHER)]), false];

        yield 'passed: epic' => [new AgentReviewPassed(), self::review([], epic: true), true];
        yield 'passed: no pull request head' => [new AgentReviewPassed(), self::review([]), false];
        yield 'passed: head without review' => [new AgentReviewPassed(), self::review([$success, $none]), false];
        yield 'passed: a pull request without a head' => [new AgentReviewPassed(), self::review([$success, $headless]), false];
        yield 'due: a pull request without a head' => [new AgentReviewDue(), self::review([$headless]), false];
        yield 'passed: one head failed' => [new AgentReviewPassed(), self::review([$success, $failure]), false];
        yield 'passed: every head passed' => [new AgentReviewPassed(), self::review([$success, self::head(AgentReviewConclusion::Success, self::OTHER)]), true];

        $overridden = self::head(AgentReviewConclusion::Failure, overriddenBy: 'ubermuda');
        yield 'due: failed review overridden by an approval' => [new AgentReviewDue(), self::review([$overridden]), false];
        yield 'due: overridden review that a fix round answered' => [new AgentReviewDue(), self::review([self::head(AgentReviewConclusion::Failure, answered: true, overriddenBy: 'ubermuda')]), false];
        yield 'failed: failed review overridden by an approval' => [new AgentReviewFailed(), self::review([$overridden]), false];
        yield 'passed: failed review overridden by an approval' => [new AgentReviewPassed(), self::review([$overridden]), false];
        yield 'overridden: a failed review overridden' => [new AgentReviewOverridden(), self::review([$overridden]), true];
        yield 'overridden: beside a passed head' => [new AgentReviewOverridden(), self::review([$overridden, self::head(AgentReviewConclusion::Success, self::OTHER)]), true];
        yield 'overridden: every head passed' => [new AgentReviewOverridden(), self::review([$success]), false];
        yield 'overridden: another head still failed' => [new AgentReviewOverridden(), self::review([$overridden, self::head(AgentReviewConclusion::Failure, self::OTHER)]), false];
        yield 'overridden: another head has no review' => [new AgentReviewOverridden(), self::review([$overridden, self::head(null, self::OTHER)]), false];
        yield 'overridden: a failed review nobody approved' => [new AgentReviewOverridden(), self::review([$failure]), false];
        yield 'overridden: no pull request head' => [new AgentReviewOverridden(), self::review([]), false];
        yield 'overridden: epic' => [new AgentReviewOverridden(), self::review([$overridden], epic: true), false];
        yield 'unposted: all posted' => [new AgentReviewUnposted(), self::review([$success]), false];
        yield 'unposted: a review waits' => [new AgentReviewUnposted(), self::review([$success], unposted: true), true];
        yield 'uncommented: none waits' => [new AgentReviewUncommented(), self::review([$success]), false];
        yield 'uncommented: a review waits' => [new AgentReviewUncommented(), self::review([$success], uncommented: ['review-1']), true];
    }

    #[DataProvider('cases')]
    public function test_the_condition_reads_the_agent_review_facts(Condition $condition, AgentReviewFacts $review, bool $expected): void
    {
        self::assertContains(AgentReviewFacts::class, $condition->reads([]));
        self::assertSame($expected, $condition->evaluate(self::facts($review), []));
    }

    public function test_due_and_failed_read_the_bound_pull_request(): void
    {
        self::assertSame([AgentReviewFacts::class, EngineFact::PullRequest], new AgentReviewDue()->reads([]));
        self::assertSame([AgentReviewFacts::class, EngineFact::PullRequest], new AgentReviewFailed()->reads([]));
        self::assertSame([AgentReviewFacts::class], new AgentReviewPassed()->reads([]));
    }

    public function test_due_and_failed_are_false_without_a_bound_pull_request(): void
    {
        $facts = FactsMother::facts(provided: [AgentReviewFacts::class => self::review([self::head(AgentReviewConclusion::Failure), self::head(null, self::OTHER)])]);

        self::assertFalse(new AgentReviewDue()->evaluate($facts, []));
        self::assertFalse(new AgentReviewFailed()->evaluate($facts, []));
    }

    public function test_each_condition_has_a_key_and_a_waiting_message_for_both_senses(): void
    {
        foreach ([new AgentReviewDue(), new AgentReviewFailed(), new AgentReviewPassed(), new AgentReviewOverridden(), new AgentReviewUnposted(), new AgentReviewUncommented()] as $condition) {
            self::assertStringStartsWith('agent_review.', $condition::key());
            self::assertSame('workflow.source.agent_review', $condition::source());
            self::assertNotSame($condition->waitingFor([])->getMessage(), $condition->waitingFor([], negated: true)->getMessage());
        }
    }

    private static function head(?AgentReviewConclusion $conclusion, string $pullRequestId = self::BOUND, bool $answered = false, ?string $overriddenBy = null): ReviewedHead
    {
        return new ReviewedHead($pullRequestId, str_repeat('a', 40), $conclusion, $answered, $overriddenBy);
    }

    /**
     * @param list<ReviewedHead> $heads
     * @param list<string>       $uncommented
     */
    private static function review(array $heads, bool $epic = false, bool $unposted = false, array $uncommented = []): AgentReviewFacts
    {
        return new AgentReviewFacts($heads, $epic, $unposted, $uncommented);
    }

    private static function facts(AgentReviewFacts $review): Facts
    {
        return FactsMother::facts(pullRequest: FactsMother::pullRequest(id: Uuid::fromString(self::BOUND)), provided: [AgentReviewFacts::class => $review]);
    }
}
