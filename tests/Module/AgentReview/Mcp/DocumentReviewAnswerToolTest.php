<?php

declare(strict_types=1);

namespace App\Tests\Module\AgentReview\Mcp;

use App\Module\AgentReview\Entity\AgentReviewCategory;
use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\AgentReview\Entity\AgentReviewFinding;
use App\Module\AgentReview\Entity\AgentReviewSeverity;
use App\Module\AgentReview\Entity\DocumentAgentReview;
use App\Module\AgentReview\Entity\DocumentReviewAnswer;
use App\Module\AgentReview\Mcp\DocumentReviewAnswerTool;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Tests\Module\AgentReview\DocumentReviewScenario;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class DocumentReviewAnswerToolTest extends KernelTestCase
{
    use DocumentReviewScenario;
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private DocumentReviewAnswerTool $tool;
    private Project $project;
    private Card $card;
    private Document $document;
    private DocumentAgentReview $review;
    private Uuid $sessionId;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $tool = self::getContainer()->get(DocumentReviewAnswerTool::class);
        self::assertInstanceOf(DocumentReviewAnswerTool::class, $tool);
        $this->tool = $tool;

        $this->project = $this->makeProject('document-review-answer');
        $this->card = $this->card($this->project);
        $this->em->flush();
        $this->document = $this->linkedDocument($this->card);
        $this->review = $this->documentReview($this->project, $this->card, $this->document);
        $this->sessionId = Uuid::v4();
        $this->actAsMcpTokenBoundTo($this->project);
    }

    public function test_it_stores_one_answer_for_each_finding(): void
    {
        $this->workRun($this->project, $this->card, $this->sessionId, 'tech-design-revise');
        $this->sendSession($this->sessionId);

        $result = $this->answer([
            ['index' => 1, 'answer' => 'not-changed', 'reason' => 'The query already filters by project.'],
            ['index' => 0, 'answer' => 'fixed'],
        ]);

        self::assertSame(['reviewId' => (string) $this->review->id, 'answered' => 2], $result);
        $this->em->clear();
        $stored = $this->em->find(DocumentAgentReview::class, $this->review->id);
        self::assertInstanceOf(DocumentAgentReview::class, $stored);
        self::assertTrue($stored->isAnswered());
        $answers = $stored->answers();
        self::assertSame([0, 1], array_map(static fn ($answer) => $answer->index, $answers));
        self::assertSame([DocumentReviewAnswer::Fixed, DocumentReviewAnswer::NotChanged], array_map(static fn ($answer) => $answer->answer, $answers));
        self::assertSame([null, 'The query already filters by project.'], array_map(static fn ($answer) => $answer->reason, $answers));
    }

    /** @return iterable<string, array{WorkRequestState}> */
    public static function withdrawnStates(): iterable
    {
        yield 'cancelled' => [WorkRequestState::Cancelled];
        yield 'expired' => [WorkRequestState::Expired];
    }

    #[DataProvider('withdrawnStates')]
    public function test_it_refuses_answers_from_a_run_whose_work_was_withdrawn(WorkRequestState $state): void
    {
        $this->workRun($this->project, $this->card, $this->sessionId, 'tech-design-revise', requestState: $state);
        $this->sendSession($this->sessionId);

        $this->assertRefused('This work was withdrawn.', fn () => $this->answer([['index' => 0, 'answer' => 'fixed'], ['index' => 1, 'answer' => 'fixed']]));
        self::assertFalse($this->review->isAnswered());
    }

    public function test_it_takes_answers_from_a_run_whose_work_is_live(): void
    {
        $this->workRun($this->project, $this->card, $this->sessionId, 'tech-design-revise', requestState: WorkRequestState::Claimed);
        $this->sendSession($this->sessionId);

        self::assertSame(2, $this->answer([['index' => 0, 'answer' => 'fixed'], ['index' => 1, 'answer' => 'fixed']])['answered']);
    }

    public function test_a_product_design_fix_worker_may_answer(): void
    {
        $this->workRun($this->project, $this->card, $this->sessionId, 'product-design-revise');
        $this->sendSession($this->sessionId);

        self::assertSame(2, $this->answer([['index' => 0, 'answer' => 'to-owner'], ['index' => 1, 'answer' => 'fixed']])['answered']);
    }

    public function test_it_answers_the_latest_review_only(): void
    {
        $this->workRun($this->project, $this->card, $this->sessionId, 'tech-design-revise');
        $this->sendSession($this->sessionId);
        $newer = $this->documentReview($this->project, $this->card, $this->document, 1, 2);

        $result = $this->answer([['index' => 0, 'answer' => 'fixed']]);

        self::assertSame((string) $newer->id, $result['reviewId']);
    }

    public function test_it_answers_the_review_of_its_own_card_when_two_cards_link_the_document(): void
    {
        $other = $this->card($this->project, 2);
        $this->em->flush();
        $this->em->persist(new CardDocument($other, $this->document));
        $this->em->flush();
        $foreign = $this->documentReview($this->project, $other, $this->document, 1, 2);
        $this->workRun($this->project, $this->card, $this->sessionId, 'tech-design-revise');
        $this->sendSession($this->sessionId);

        $result = $this->answer([['index' => 0, 'answer' => 'fixed'], ['index' => 1, 'answer' => 'fixed']]);

        self::assertSame((string) $this->review->id, $result['reviewId']);
        self::assertFalse($foreign->isAnswered());
    }

    public function test_it_refuses_when_only_another_card_has_a_review_of_the_document(): void
    {
        $other = $this->card($this->project, 2);
        $this->em->flush();
        $shared = $this->linkedDocument($other);
        $this->em->persist(new CardDocument($this->card, $shared));
        $this->em->flush();
        $this->documentReview($this->project, $other, $shared);
        $this->workRun($this->project, $this->card, $this->sessionId, 'tech-design-revise');
        $this->sendSession($this->sessionId);

        $this->assertRefused('has no agent review of this card to answer', fn () => ($this->tool)((string) $this->card->id, (string) $shared->id, []));
    }

    public function test_it_refuses_a_second_answer(): void
    {
        $this->workRun($this->project, $this->card, $this->sessionId, 'tech-design-revise');
        $this->sendSession($this->sessionId);
        $this->answer([['index' => 0, 'answer' => 'fixed'], ['index' => 1, 'answer' => 'fixed']]);

        $this->assertRefused('already has its answers', fn () => $this->answer([['index' => 0, 'answer' => 'fixed'], ['index' => 1, 'answer' => 'fixed']]));
    }

    public function test_it_refuses_a_review_worker_and_a_call_with_no_session(): void
    {
        $this->workRun($this->project, $this->card, $this->sessionId, 'design-review');
        $this->assertRefused('takes answers only from a running design fix worker', fn () => $this->answer([]));

        $this->sendSession($this->sessionId);
        $this->assertRefused('takes answers only from a running design fix worker', fn () => $this->answer([]));
    }

    public function test_it_refuses_incomplete_and_inconsistent_answers(): void
    {
        $this->workRun($this->project, $this->card, $this->sessionId, 'tech-design-revise');
        $this->sendSession($this->sessionId);

        $this->assertRefused('Answer every finding', fn () => $this->answer([['index' => 0, 'answer' => 'fixed']]));
        $this->assertRefused('names a finding that the latest review does not have', fn () => $this->answer([['index' => 0, 'answer' => 'fixed'], ['index' => 2, 'answer' => 'fixed']]));
        $this->assertRefused('name the same finding', fn () => $this->answer([['index' => 0, 'answer' => 'fixed'], ['index' => 0, 'answer' => 'fixed']]));
        $this->assertRefused('needs a reason', fn () => $this->answer([['index' => 0, 'answer' => 'fixed'], ['index' => 1, 'answer' => 'not-changed', 'reason' => '  ']]));
        $this->assertRefused('answers[0].answer must be one of', fn () => $this->answer([['index' => 0, 'answer' => 'maybe']]));
        $this->assertRefused('answers[0].index must be a finding position', fn () => $this->answer([['index' => -1, 'answer' => 'fixed']]));
        $reviewId = $this->review->id;
        $this->em->clear();
        $stored = $this->em->find(DocumentAgentReview::class, $reviewId);
        self::assertInstanceOf(DocumentAgentReview::class, $stored);
        self::assertFalse($stored->isAnswered());
    }

    public function test_a_finding_that_needs_a_person_takes_only_to_owner(): void
    {
        $this->workRun($this->project, $this->card, $this->sessionId, 'tech-design-revise');
        $this->sendSession($this->sessionId);
        $document = $this->linkedDocument($this->card);
        $review = new DocumentAgentReview($this->project, $this->card, $document, 1, 'Passed.', AgentReviewConclusion::Success, [
            new AgentReviewFinding(null, null, null, AgentReviewSeverity::Important, 'Ask', 'Body', AgentReviewCategory::Spec, true),
        ]);
        $this->em->persist($review);
        $this->em->flush();

        $this->assertRefused('takes the answer to-owner', fn () => ($this->tool)((string) $this->card->id, (string) $document->id, [['index' => 0, 'answer' => 'not-changed', 'reason' => 'The design is right.']]));
        $this->assertRefused('takes the answer to-owner', fn () => ($this->tool)((string) $this->card->id, (string) $document->id, [['index' => 0, 'answer' => 'fixed']]));
        self::assertFalse($review->isAnswered());

        ($this->tool)((string) $this->card->id, (string) $document->id, [['index' => 0, 'answer' => 'to-owner']]);
        self::assertTrue($review->isAnswered());
    }

    public function test_it_refuses_a_document_with_no_review_and_one_the_card_does_not_link(): void
    {
        $this->workRun($this->project, $this->card, $this->sessionId, 'tech-design-revise');
        $this->sendSession($this->sessionId);
        $bare = $this->linkedDocument($this->card);
        $other = $this->card($this->project, 2);
        $this->em->flush();
        $foreign = $this->linkedDocument($other);

        $this->assertRefused('has no agent review of this card to answer', fn () => ($this->tool)((string) $this->card->id, (string) $bare->id, []));
        $this->assertRefused('must name a document that the card links', fn () => ($this->tool)((string) $this->card->id, (string) $foreign->id, []));
    }

    public function test_a_review_with_no_findings_takes_an_empty_answer(): void
    {
        $this->workRun($this->project, $this->card, $this->sessionId, 'tech-design-revise');
        $this->sendSession($this->sessionId);
        $clean = $this->linkedDocument($this->card);
        $review = $this->documentReview($this->project, $this->card, $clean, 0);

        self::assertSame(['reviewId' => (string) $review->id, 'answered' => 0], ($this->tool)((string) $this->card->id, (string) $clean->id, []));
        self::assertTrue($review->isAnswered());
    }

    /**
     * @param list<array<string, mixed>> $answers
     *
     * @return array{reviewId: string, answered: int}
     */
    private function answer(array $answers): array
    {
        return ($this->tool)((string) $this->card->id, (string) $this->document->id, $answers);
    }

    private function assertRefused(string $message, \Closure $call): void
    {
        try {
            $call();
            self::fail('The call was not refused.');
        } catch (ToolCallException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }
}
