<?php

declare(strict_types=1);

namespace App\Tests\Module\AgentReview\Service;

use App\Module\AgentReview\Entity\AgentReview;
use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\AgentReview\Service\AgentReviewOverride;
use App\Module\Board\Entity\Card;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Project\Entity\Project;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AgentReviewOverrideTest extends TestCase
{
    private const string HEAD = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function test_an_approval_of_the_head_after_a_failed_review_overrides_it(): void
    {
        self::assertSame('ubermuda', new AgentReviewOverride()->approverOf($this->review(), $this->pullRequest()));
    }

    /** @return iterable<string, array{AgentReviewConclusion, callable(ForgePullRequest): void}> */
    public static function reviewsThatStand(): iterable
    {
        yield 'the review passed' => [AgentReviewConclusion::Success, static function (): void {}];
        yield 'the approval came before the review' => [AgentReviewConclusion::Failure, static function (ForgePullRequest $pr): void {
            $pr->latestApprovedAt = new \DateTimeImmutable('2026-10-12 09:00:00');
        }];
        yield 'the approval came at the time of the review' => [AgentReviewConclusion::Failure, static function (ForgePullRequest $pr): void {
            $pr->latestApprovedAt = new \DateTimeImmutable('2026-10-12 10:00:00');
        }];
        yield 'no approval stands' => [AgentReviewConclusion::Failure, static function (ForgePullRequest $pr): void {
            $pr->review = PullRequestReview::None;
            $pr->latestApprovedAt = $pr->latestApprovalId = $pr->latestApproverLogin = null;
        }];
        yield 'the approval covers an older head' => [AgentReviewConclusion::Failure, static function (ForgePullRequest $pr): void {
            $pr->coveredSha = str_repeat('b', 40);
        }];
        yield 'the coverage is unknown' => [AgentReviewConclusion::Failure, static function (ForgePullRequest $pr): void {
            $pr->coveredSha = null;
        }];
        yield 'someone requested changes' => [AgentReviewConclusion::Failure, static function (ForgePullRequest $pr): void {
            $pr->review = PullRequestReview::ChangesRequested;
        }];
        yield 'the pull request is merged' => [AgentReviewConclusion::Failure, static function (ForgePullRequest $pr): void {
            $pr->state = PullRequestState::Merged;
        }];
        yield 'the newest approval has no id' => [AgentReviewConclusion::Failure, static function (ForgePullRequest $pr): void {
            $pr->latestApprovalId = null;
        }];
        yield 'the newest approval has no time' => [AgentReviewConclusion::Failure, static function (ForgePullRequest $pr): void {
            $pr->latestApprovedAt = null;
        }];
    }

    /** @param callable(ForgePullRequest): void $change */
    #[DataProvider('reviewsThatStand')]
    public function test_the_review_stands(AgentReviewConclusion $conclusion, callable $change): void
    {
        $pullRequest = $this->pullRequest();
        $change($pullRequest);

        self::assertNull(new AgentReviewOverride()->approverOf($this->review($conclusion), $pullRequest));
    }

    private function review(AgentReviewConclusion $conclusion = AgentReviewConclusion::Failure): AgentReview
    {
        return new AgentReview(
            $this->createStub(Project::class),
            $this->createStub(Card::class),
            $this->pullRequest(),
            self::HEAD,
            'Done.',
            $conclusion,
            [],
            createdAt: new \DateTimeImmutable('2026-10-12 10:00:00'),
        );
    }

    private function pullRequest(): ForgePullRequest
    {
        $pullRequest = new ForgePullRequest($this->createStub(Project::class), 'github', 'acme/widgets', 7);
        $pullRequest->headSha = self::HEAD;
        $pullRequest->coveredSha = self::HEAD;
        $pullRequest->review = PullRequestReview::Approved;
        $pullRequest->latestApprovedAt = new \DateTimeImmutable('2026-10-12 10:40:00');
        $pullRequest->latestApprovalId = 'PRR_latest';
        $pullRequest->latestApproverLogin = 'ubermuda';

        return $pullRequest;
    }
}
