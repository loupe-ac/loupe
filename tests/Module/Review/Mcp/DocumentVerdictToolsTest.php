<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Mcp;

use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Review;
use App\Module\Review\Entity\Verdict;
use App\Module\Review\Mcp\DocumentDecisionAnswerTool;
use App\Module\Review\Mcp\DocumentVerdictSubmitTool;
use App\Module\Review\Mcp\DocumentVerdictWithdrawTool;
use App\Module\Review\Repository\ReviewRepository;
use App\Module\Review\ReviewEventType;
use App\Outbox\Repository\OutboxEventRepository;
use App\Tests\Module\Board\BoardColumnFixtures;
use App\Tests\Support\McpTokenScenario;
use App\Tests\Support\RecordingAuditor;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class DocumentVerdictToolsTest extends KernelTestCase
{
    use BoardColumnFixtures;
    use DecisionDocumentScenario;
    use McpTokenScenario;

    private DocumentVerdictSubmitTool $submit;
    private DocumentVerdictWithdrawTool $withdraw;
    private RecordingAuditor $audit;

    protected function setUp(): void
    {
        $this->bootScenario();
        $this->audit = RecordingAuditor::installedIn(self::getContainer());
        $submit = self::getContainer()->get(DocumentVerdictSubmitTool::class);
        self::assertInstanceOf(DocumentVerdictSubmitTool::class, $submit);
        $this->submit = $submit;
        $withdraw = self::getContainer()->get(DocumentVerdictWithdrawTool::class);
        self::assertInstanceOf(DocumentVerdictWithdrawTool::class, $withdraw);
        $this->withdraw = $withdraw;
    }

    public function test_an_approval_is_recorded_for_the_owner_and_marked_as_recorded_through_an_agent(): void
    {
        $document = $this->answeredDocument();

        $result = ($this->submit)((string) $document->id, 1, 'approved', null);

        $review = $this->newest($document);
        self::assertSame(Verdict::Approved, $review->verdict);
        self::assertSame($document->owner, $review->reviewer);
        self::assertTrue($review->throughAgent);
        self::assertSame(DocumentStatus::Approved, $document->status);
        self::assertSame(['verdictId' => (string) $review->id, 'verdict' => 'approved', 'documentStatus' => 'approved'], $result);

        $record = $this->audit->record('review.document_verdict_submitted');
        self::assertSame('approved', $record->context['verdict']);
        self::assertTrue($record->context['throughAgent']);
    }

    public function test_an_approval_reaches_the_workflow_with_the_card_of_the_document(): void
    {
        $document = $this->answeredDocument();
        $card = $this->linkedCard($document);

        ($this->submit)((string) $document->id, 1, 'approved', null);

        $outbox = self::getContainer()->get(OutboxEventRepository::class);
        self::assertInstanceOf(OutboxEventRepository::class, $outbox);
        $rows = $outbox->findBy(['project' => $document->project->id, 'type' => ReviewEventType::REVIEW_SUBMITTED]);
        self::assertCount(1, $rows);
        $payload = json_decode($rows[0]->payload, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('approved', $payload['verdict']);
        self::assertSame([(string) $card->id], $payload['cardIds']);
    }

    public function test_changes_requested_needs_a_note_or_an_open_comment(): void
    {
        $document = $this->decisionDocument();
        $this->actAsMcpTokenBoundTo($document->project);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('note: Write a review note, or leave a comment on the passage to change.');
        ($this->submit)((string) $document->id, 1, 'changes-requested', null);
    }

    public function test_changes_requested_with_a_note_is_recorded(): void
    {
        $document = $this->decisionDocument();
        $this->actAsMcpTokenBoundTo($document->project);

        ($this->submit)((string) $document->id, 1, 'changes-requested', null, 'Shorten D2.');

        $review = $this->newest($document);
        self::assertSame(Verdict::ChangesRequested, $review->verdict);
        self::assertSame('Shorten D2.', $review->note);
    }

    public function test_an_approval_with_an_unanswered_decision_is_refused_with_the_count(): void
    {
        $document = $this->decisionDocument();
        $this->actAsMcpTokenBoundTo($document->project);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('verdict: 2 decisions have no answer yet. Answer them with document_decision_answer first, or request changes.');
        ($this->submit)((string) $document->id, 1, 'approved', null);
    }

    public function test_a_stale_version_names_the_newer_version_for_a_submit(): void
    {
        $document = $this->decisionDocument();
        $this->reviseDocument($document, 'Reworded the options');
        $this->actAsMcpTokenBoundTo($document->project);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('The document has version 2 now: Reworded the options. Show it to the owner, then call again with versionNumber 2.');
        ($this->submit)((string) $document->id, 1, 'changes-requested', null, 'Late.');
    }

    public function test_a_stale_expected_verdict_id_tells_the_agent_to_read_the_review_again(): void
    {
        $document = $this->decisionDocument();
        $this->actAsMcpTokenBoundTo($document->project);
        ($this->submit)((string) $document->id, 1, 'changes-requested', null, 'First.');
        $this->audit->forget();

        try {
            ($this->submit)((string) $document->id, 1, 'changes-requested', null, 'Second.');
            self::fail('The verdict was not refused.');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('document_get_review', $e->getMessage());
            self::assertStringContainsString('newer verdict', $e->getMessage());
        }
        self::assertSame([], $this->audit->operations());
    }

    public function test_a_verdict_that_replaces_the_one_the_agent_read_is_accepted(): void
    {
        $document = $this->decisionDocument();
        $this->actAsMcpTokenBoundTo($document->project);
        ($this->submit)((string) $document->id, 1, 'changes-requested', null, 'First.');
        $first = $this->newest($document);

        ($this->submit)((string) $document->id, 1, 'changes-requested', (string) $first->id, 'Second.');

        self::assertSame(2, $this->newest($document)->sequence);
    }

    public function test_an_unknown_verdict_is_refused(): void
    {
        $document = $this->decisionDocument();
        $this->actAsMcpTokenBoundTo($document->project);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('verdict: That verdict is not one this document accepts.');
        ($this->submit)((string) $document->id, 1, 'withdrawn', null);
    }

    public function test_a_withdrawal_takes_the_verdict_back_and_is_marked(): void
    {
        $document = $this->answeredDocument();
        ($this->submit)((string) $document->id, 1, 'approved', null);
        $approval = $this->newest($document);
        $this->audit->forget();

        $result = ($this->withdraw)((string) $document->id, 1, (string) $approval->id);

        $withdrawal = $this->newest($document);
        self::assertSame(Verdict::Withdrawn, $withdrawal->verdict);
        self::assertTrue($withdrawal->throughAgent);
        self::assertSame(DocumentStatus::InReview, $document->status);
        self::assertSame(['verdictId' => (string) $withdrawal->id, 'documentStatus' => 'in-review'], $result);
        self::assertTrue($this->audit->record('review.document_verdict_undone')->context['throughAgent']);
    }

    public function test_a_stale_verdict_id_is_refused_on_a_withdrawal(): void
    {
        $document = $this->decisionDocument();
        $this->actAsMcpTokenBoundTo($document->project);
        ($this->submit)((string) $document->id, 1, 'changes-requested', null, 'First.');
        $first = $this->newest($document);
        ($this->submit)((string) $document->id, 1, 'changes-requested', (string) $first->id, 'Second.');
        $this->audit->forget();

        try {
            ($this->withdraw)((string) $document->id, 1, (string) $first->id);
            self::fail('The withdrawal was not refused.');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('document_get_review', $e->getMessage());
        }
        self::assertSame([], $this->audit->operations());
        self::assertSame(Verdict::ChangesRequested, $this->newest($document)->verdict);
    }

    public function test_a_stale_version_names_the_newer_version_for_a_withdrawal(): void
    {
        $document = $this->decisionDocument();
        $this->actAsMcpTokenBoundTo($document->project);
        ($this->submit)((string) $document->id, 1, 'changes-requested', null, 'First.');
        $first = $this->newest($document);
        $this->reviseDocument($document, 'Reworded the options');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('The document has version 2 now: Reworded the options. Show it to the owner, then call again with versionNumber 2.');
        ($this->withdraw)((string) $document->id, 1, (string) $first->id);
    }

    public function test_a_bridge_worker_is_refused_for_both_tools(): void
    {
        $document = $this->decisionDocument();
        $this->actAsMcpTokenBoundTo($document->project);
        $this->workerSession($document);
        $this->audit->forget();

        foreach ([
            'record' => fn () => ($this->submit)((string) $document->id, 1, 'changes-requested', null, 'Note.'),
            'withdraw' => fn () => ($this->withdraw)((string) $document->id, 1, (string) Uuid::v7()),
        ] as $verb => $call) {
            try {
                $call();
                self::fail('The worker was not refused.');
            } catch (ToolCallException $e) {
                self::assertSame(\sprintf('A bridge worker cannot %s a verdict for the owner. Ask the owner with inbox_ask.', $verb), $e->getMessage());
            }
        }
        self::assertSame([], $this->audit->operations());
        self::assertSame(DocumentStatus::InReview, $document->status);
    }

    public function test_a_token_of_another_project_cannot_reach_the_document(): void
    {
        $document = $this->decisionDocument();
        $other = $this->decisionDocument();
        $this->actAsMcpTokenBoundTo($other->project);

        try {
            ($this->submit)((string) $document->id, 1, 'changes-requested', null, 'Note.');
            self::fail('The verdict was not refused.');
        } catch (ToolCallException $e) {
            self::assertSame(\sprintf('Document "%s" not found or not accessible.', $document->id), $e->getMessage());
        }
        self::assertSame(DocumentStatus::InReview, $document->status);
    }

    private function answeredDocument(): Document
    {
        $document = $this->decisionDocument();
        $this->actAsMcpTokenBoundTo($document->project);
        $answer = self::getContainer()->get(DocumentDecisionAnswerTool::class);
        self::assertInstanceOf(DocumentDecisionAnswerTool::class, $answer);
        $answer((string) $document->id, 1, 'features', [0]);
        $answer((string) $document->id, 1, 'target', [1]);
        $this->audit->forget();

        return $document;
    }

    private function newest(Document $document): Review
    {
        $reviews = self::getContainer()->get(ReviewRepository::class);
        self::assertInstanceOf(ReviewRepository::class, $reviews);

        return $reviews->findNewestByVersion($document->currentVersion()) ?? throw new \LogicException('The document has no verdict.');
    }

    private function linkedCard(Document $document): Card
    {
        $this->seedColumns($document->project);
        $this->em->flush();
        $handler = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $handler);

        return $handler(new CreateCardCommand(
            project: $document->project,
            title: 'A card',
            body: 'Body',
            type: 'feature',
            column: $this->column($document->project, BoardColumn::BACKLOG_SLUG),
            documentIds: [(string) $document->id],
        ));
    }
}
