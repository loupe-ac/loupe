<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Service;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Tag;
use App\Module\Review\Service\AgentReviewSummary;
use App\Module\Review\Service\DocumentAgentReviews;
use App\Module\Workflow\Contract\DesignReviewRoundLimit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * @phpstan-import-type DocumentAgentReviewSummary from DocumentAgentReviews
 */
final class AgentReviewSummaryTest extends TestCase
{
    private AgentReviewSummary $summary;

    protected function setUp(): void
    {
        $this->summary = new AgentReviewSummary($this->createStub(DocumentAgentReviews::class), $this->createStub(DesignReviewRoundLimit::class));
    }

    /**
     * @param list<array{0: string, 1: ?string, 2?: ?string}> $findings severity, answer, reason
     *
     * @return DocumentAgentReviewSummary
     */
    private function review(int $version, string $conclusion, array $findings = [], bool $needsPerson = false, bool $answered = true, string $card = 'card'): array
    {
        return [
            'reviewId' => 'review-'.$version,
            'cardId' => $card,
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
        self::assertNull($this->summary->summarize([], DocumentStatus::InReview, 6));
    }

    public function test_a_passed_review_counts_what_the_rounds_fixed_kept_and_left_open(): void
    {
        $result = $this->summary->summarize([
            $this->review(2, 'success', [['nit', null]]),
            $this->review(1, 'failure', [['important', 'fixed'], ['important', 'fixed'], ['important', 'not-changed', 'Decision 6 changed.'], ['nit', 'fixed']]),
        ], DocumentStatus::InReview, 6);

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
        ], DocumentStatus::InReview, 6);

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
        ], DocumentStatus::InReview, 6);

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
        ], DocumentStatus::InReview, 6);

        self::assertNotNull($result);
        self::assertSame('skipped', $result['state']);
    }

    public function test_a_published_document_with_a_failed_latest_review_is_skipped(): void
    {
        $result = $this->summary->summarize([$this->review(1, 'failure', [['important', null], ['important', null]])], DocumentStatus::Approved, 6);

        self::assertNotNull($result);
        self::assertSame('skipped', $result['state']);
        self::assertSame(1, $result['rounds']);
        self::assertSame(['important' => 2], $result['open']);
    }

    public function test_a_draft_still_in_its_loop_has_no_summary(): void
    {
        self::assertNull($this->summary->summarize([$this->review(1, 'failure', [['important', null]])], DocumentStatus::Draft, 6));
    }

    public function test_a_draft_has_no_summary_after_a_passed_review(): void
    {
        self::assertNull($this->summary->summarize([$this->review(1, 'success', [['nit', 'to-owner', 'Pick one.']])], DocumentStatus::Draft, 6));
    }

    public function test_a_draft_has_no_summary_after_three_failed_reviews(): void
    {
        self::assertNull($this->summary->summarize([
            $this->review(3, 'failure', [['important', null]]),
            $this->review(2, 'failure', [['important', 'fixed']]),
            $this->review(1, 'failure', [['important', 'fixed']]),
        ], DocumentStatus::Draft, 6));
    }

    public function test_a_finding_for_the_owner_stays_open(): void
    {
        $result = $this->summary->summarize([$this->review(1, 'success', [['nit', 'to-owner', 'Pick one.']])], DocumentStatus::InReview, 6);

        self::assertNotNull($result);
        self::assertSame('passed', $result['state']);
        self::assertSame(['nit' => 1], $result['open']);
        self::assertSame('to-owner', $result['roundList'][0]['findings'][0]['status']);
    }

    public function test_a_publish_before_the_fix_round_answers_a_question_for_the_owner_is_skipped(): void
    {
        $result = $this->summary->summarize([$this->review(1, 'success', [['important', null]], needsPerson: true, answered: false)], DocumentStatus::InReview, 6);

        self::assertNotNull($result);
        self::assertSame('skipped', $result['state']);
        self::assertSame(['important' => 1], $result['open']);
    }

    public function test_a_passed_review_whose_question_for_the_owner_is_answered_passed(): void
    {
        $result = $this->summary->summarize([$this->review(1, 'success', [['important', 'to-owner']], needsPerson: true)], DocumentStatus::InReview, 6);

        self::assertNotNull($result);
        self::assertSame('passed', $result['state']);
    }

    public function test_an_unanswered_passed_review_with_no_question_for_the_owner_passed(): void
    {
        $result = $this->summary->summarize([$this->review(1, 'success', [['nit', null]], answered: false)], DocumentStatus::InReview, 6);

        self::assertNotNull($result);
        self::assertSame('passed', $result['state']);
    }

    public function test_the_limit_of_the_project_decides_when_the_loop_gave_up(): void
    {
        $reviews = [
            $this->review(3, 'failure', [['important', null]]),
            $this->review(2, 'failure', [['important', 'fixed']]),
            $this->review(1, 'failure', [['important', 'fixed']]),
        ];

        self::assertSame('failed', $this->summary->summarize($reviews, DocumentStatus::InReview, 3)['state'] ?? null);
        self::assertSame('skipped', $this->summary->summarize($reviews, DocumentStatus::InReview, 4)['state'] ?? null);
    }

    public function test_a_pass_with_a_finding_for_the_owner_uses_a_round_of_the_limit(): void
    {
        $reviews = [
            $this->review(3, 'failure', [['important', null]]),
            $this->review(2, 'success', [['important', 'to-owner']], needsPerson: true),
            $this->review(1, 'failure', [['important', 'fixed']]),
        ];

        self::assertSame('failed', $this->summary->summarize($reviews, DocumentStatus::InReview, 3)['state'] ?? null);
        self::assertSame('skipped', $this->summary->summarize($reviews, DocumentStatus::InReview, 4)['state'] ?? null);
    }

    public function test_a_pass_with_no_finding_for_the_owner_uses_no_round(): void
    {
        $reviews = [
            $this->review(3, 'failure', [['important', null]]),
            $this->review(2, 'success', [['nit', 'fixed']]),
            $this->review(1, 'failure', [['important', 'fixed']]),
        ];

        self::assertSame('skipped', $this->summary->summarize($reviews, DocumentStatus::InReview, 3)['state'] ?? null);
    }

    public function test_an_answered_pass_for_the_owner_at_the_limit_passed(): void
    {
        $reviews = [
            $this->review(3, 'success', [['important', 'to-owner']], needsPerson: true),
            $this->review(2, 'failure', [['important', 'fixed']]),
            $this->review(1, 'failure', [['important', 'fixed']]),
        ];

        self::assertSame('passed', $this->summary->summarize($reviews, DocumentStatus::InReview, 3)['state'] ?? null);
    }

    public function test_a_workflow_with_no_limit_never_says_the_loop_failed(): void
    {
        $reviews = array_map(fn (int $version): array => $this->review($version, 'failure', [['important', null]]), [6, 5, 4, 3, 2, 1]);

        self::assertSame('skipped', $this->summary->summarize($reviews, DocumentStatus::InReview, null)['state'] ?? null);
    }

    public function test_only_the_failed_reviews_of_the_newest_reviews_card_count(): void
    {
        $reviews = [
            $this->review(3, 'failure', [['important', null]], card: 'card-b'),
            $this->review(2, 'failure', [['important', 'fixed']], card: 'card-b'),
            $this->review(1, 'failure', [['important', 'fixed']], card: 'card-a'),
        ];

        $result = $this->summary->summarize($reviews, DocumentStatus::InReview, 3);

        self::assertNotNull($result);
        self::assertSame('skipped', $result['state']);
        self::assertCount(3, $result['roundList']);
    }

    public function test_for_document_asks_the_workflow_with_the_project_and_the_tags_of_the_document(): void
    {
        $document = $this->document(DocumentStatus::InReview, ['tech-design', 'plan']);
        $reviews = $this->createStub(DocumentAgentReviews::class);
        $reviews->method('forDocument')->willReturn([$this->review(1, 'failure', [['important', null]])]);
        $limit = $this->createMock(DesignReviewRoundLimit::class);
        $limit->expects($this->once())->method('of')->with($document->project->id, ['tech-design', 'plan'])->willReturn(1);

        $result = new AgentReviewSummary($reviews, $limit)->forDocument($document);

        self::assertSame('failed', $result['state'] ?? null);
    }

    public function test_for_document_does_not_ask_the_workflow_for_a_draft_or_an_unreviewed_document(): void
    {
        $reviews = $this->createStub(DocumentAgentReviews::class);
        $reviews->method('forDocument')->willReturnOnConsecutiveCalls([$this->review(1, 'failure')], []);
        $limit = $this->createMock(DesignReviewRoundLimit::class);
        $limit->expects($this->never())->method('of');
        $summary = new AgentReviewSummary($reviews, $limit);

        self::assertNull($summary->forDocument($this->document(DocumentStatus::Draft, ['tech-design'])));
        self::assertNull($summary->forDocument($this->document(DocumentStatus::InReview, ['tech-design'])));
    }

    /** @param list<string> $tags */
    private function document(DocumentStatus $status, array $tags): Document
    {
        $project = new Project(new User(fullName: 'Riley Chen', email: 'riley@example.com', password: 'x'), 'Loupe');
        new \ReflectionProperty(Project::class, 'id')->setValue($project, Uuid::v7());
        $document = new Document($project->owner, $project, 'Design');
        $document->status = $status;
        foreach ($tags as $name) {
            $document->tags->add(new Tag($project, $name));
        }

        return $document;
    }
}
