<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Service;

use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Service\AgentReviewSummary;
use App\Module\Review\Service\DocumentAgentReviews;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type DocumentAgentReviewSummary from DocumentAgentReviews
 */
final class AgentReviewSummaryTest extends TestCase
{
    private AgentReviewSummary $summary;

    protected function setUp(): void
    {
        $this->summary = new AgentReviewSummary($this->createStub(DocumentAgentReviews::class));
    }

    /**
     * @param list<array{0: string, 1: ?string, 2?: ?string}> $findings severity, answer, reason
     *
     * @return DocumentAgentReviewSummary
     */
    private function review(int $version, string $conclusion, array $findings = [], bool $needsPerson = false, bool $answered = true): array
    {
        return [
            'reviewId' => 'review-'.$version,
            'cardId' => 'card',
            'versionNumber' => $version,
            'conclusion' => $conclusion,
            'summary' => 'Summary.',
            'findings' => array_map(static fn (array $finding, int $index): array => [
                'index' => $index,
                'severity' => $finding[0],
                'title' => 'Finding '.$index,
                'body' => 'Body '.$index,
                'needsPerson' => $needsPerson,
                'answer' => null === $finding[1] ? null : ['answer' => $finding[1], 'reason' => $finding[2] ?? null],
            ], $findings, array_keys($findings)),
            'answered' => $answered,
            'answeredAt' => null,
            'createdAt' => '2026-10-16T10:00:00+00:00',
        ];
    }

    public function test_a_document_with_no_review_has_no_summary(): void
    {
        self::assertNull($this->summary->summarize([], DocumentStatus::InReview));
    }

    public function test_a_passed_review_counts_what_the_rounds_fixed_kept_and_left_open(): void
    {
        $result = $this->summary->summarize([
            $this->review(2, 'success', [['nit', null]]),
            $this->review(1, 'failure', [['important', 'fixed'], ['important', 'fixed'], ['important', 'not-changed', 'Decision 6 changed.'], ['nit', 'fixed']]),
        ], DocumentStatus::InReview);

        self::assertNotNull($result);
        self::assertSame('passed', $result['state']);
        self::assertSame(2, $result['rounds']);
        self::assertSame(3, $result['fixed']);
        self::assertSame(1, $result['notChanged']);
        self::assertSame(['nit' => 1], $result['open']);
        self::assertSame([2, 1], array_column($result['roundList'], 'number'));
    }

    public function test_an_earlier_round_lists_findings_only_when_one_was_not_fixed(): void
    {
        $result = $this->summary->summarize([
            $this->review(3, 'success'),
            $this->review(2, 'failure', [['important', 'fixed'], ['important', 'fixed']]),
            $this->review(1, 'failure', [['important', 'fixed'], ['important', 'not-changed', 'Kept.']]),
        ], DocumentStatus::InReview);

        self::assertNotNull($result);
        self::assertSame([true, false, true], array_column($result['roundList'], 'listed'));
        self::assertSame(3, $result['roundList'][1]['fixedInVersion']);
        self::assertSame(2, $result['roundList'][2]['fixedInVersion']);
        self::assertSame('fixed', $result['roundList'][2]['findings'][0]['status']);
        self::assertSame('Kept.', $result['roundList'][2]['findings'][1]['reason']);
    }

    public function test_six_failed_reviews_did_not_pass_and_name_the_open_important_findings(): void
    {
        $result = $this->summary->summarize([
            $this->review(6, 'failure', [['important', null], ['important', null]]),
            $this->review(5, 'failure', [['important', 'fixed']]),
            $this->review(4, 'failure', [['important', 'fixed']]),
            $this->review(3, 'failure', [['important', 'fixed']]),
            $this->review(2, 'failure', [['important', 'fixed']]),
            $this->review(1, 'failure', [['important', 'fixed']]),
        ], DocumentStatus::InReview);

        self::assertNotNull($result);
        self::assertSame('failed', $result['state']);
        self::assertSame(['important' => 2], $result['open']);
        self::assertSame(5, $result['fixed']);
    }

    public function test_five_failed_reviews_have_not_used_the_loop_and_read_as_skipped(): void
    {
        $result = $this->summary->summarize([
            $this->review(5, 'failure', [['important', null]]),
            $this->review(4, 'failure', [['important', 'fixed']]),
            $this->review(3, 'failure', [['important', 'fixed']]),
            $this->review(2, 'failure', [['important', 'fixed']]),
            $this->review(1, 'failure', [['important', 'fixed']]),
        ], DocumentStatus::InReview);

        self::assertNotNull($result);
        self::assertSame('skipped', $result['state']);
    }

    public function test_a_published_document_with_a_failed_latest_review_is_skipped(): void
    {
        $result = $this->summary->summarize([$this->review(1, 'failure', [['important', null], ['important', null]])], DocumentStatus::Approved);

        self::assertNotNull($result);
        self::assertSame('skipped', $result['state']);
        self::assertSame(1, $result['rounds']);
        self::assertSame(['important' => 2], $result['open']);
    }

    public function test_a_draft_still_in_its_loop_has_no_summary(): void
    {
        self::assertNull($this->summary->summarize([$this->review(1, 'failure', [['important', null]])], DocumentStatus::Draft));
    }

    public function test_a_draft_has_no_summary_after_a_passed_review(): void
    {
        self::assertNull($this->summary->summarize([$this->review(1, 'success', [['nit', 'to-owner', 'Pick one.']])], DocumentStatus::Draft));
    }

    public function test_a_draft_has_no_summary_after_three_failed_reviews(): void
    {
        self::assertNull($this->summary->summarize([
            $this->review(3, 'failure', [['important', null]]),
            $this->review(2, 'failure', [['important', 'fixed']]),
            $this->review(1, 'failure', [['important', 'fixed']]),
        ], DocumentStatus::Draft));
    }

    public function test_a_finding_for_the_owner_stays_open(): void
    {
        $result = $this->summary->summarize([$this->review(1, 'success', [['nit', 'to-owner', 'Pick one.']])], DocumentStatus::InReview);

        self::assertNotNull($result);
        self::assertSame('passed', $result['state']);
        self::assertSame(['nit' => 1], $result['open']);
        self::assertSame('to-owner', $result['roundList'][0]['findings'][0]['status']);
    }

    public function test_a_publish_before_the_fix_round_answers_a_question_for_the_owner_is_skipped(): void
    {
        $result = $this->summary->summarize([$this->review(1, 'success', [['important', null]], needsPerson: true, answered: false)], DocumentStatus::InReview);

        self::assertNotNull($result);
        self::assertSame('skipped', $result['state']);
        self::assertSame(['important' => 1], $result['open']);
    }

    public function test_a_passed_review_whose_question_for_the_owner_is_answered_passed(): void
    {
        $result = $this->summary->summarize([$this->review(1, 'success', [['important', 'to-owner']], needsPerson: true)], DocumentStatus::InReview);

        self::assertNotNull($result);
        self::assertSame('passed', $result['state']);
    }

    public function test_an_unanswered_passed_review_with_no_question_for_the_owner_passed(): void
    {
        $result = $this->summary->summarize([$this->review(1, 'success', [['nit', null]], answered: false)], DocumentStatus::InReview);

        self::assertNotNull($result);
        self::assertSame('passed', $result['state']);
    }
}
