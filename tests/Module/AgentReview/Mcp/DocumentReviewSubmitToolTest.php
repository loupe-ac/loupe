<?php

declare(strict_types=1);

namespace App\Tests\Module\AgentReview\Mcp;

use App\Module\AgentReview\Entity\AgentReviewSeverity;
use App\Module\AgentReview\Entity\DocumentAgentReview;
use App\Module\AgentReview\Mcp\DocumentReviewSubmitTool;
use App\Module\AgentReview\Repository\DocumentAgentReviewRepository;
use App\Module\Board\Entity\Card;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Tests\Module\AgentReview\DocumentReviewScenario;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class DocumentReviewSubmitToolTest extends KernelTestCase
{
    use DocumentReviewScenario;
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private DocumentReviewSubmitTool $tool;
    private Project $project;
    private Card $card;
    private Document $document;
    private Uuid $sessionId;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $tool = self::getContainer()->get(DocumentReviewSubmitTool::class);
        self::assertInstanceOf(DocumentReviewSubmitTool::class, $tool);
        $this->tool = $tool;

        $this->project = $this->makeProject('document-review-submit');
        $this->card = $this->card($this->project);
        $this->em->flush();
        $this->document = $this->linkedDocument($this->card, versions: 2);
        $this->sessionId = Uuid::v4();
        $this->actAsMcpTokenBoundTo($this->project);
    }

    public function test_it_stores_a_failing_review_for_an_important_finding(): void
    {
        $run = $this->workRun($this->project, $this->card, $this->sessionId, 'design-review');
        $this->sendSession($this->sessionId);

        $result = $this->submit(2, [$this->finding('important'), $this->finding('nit')]);

        self::assertSame(['conclusion' => 'failure', 'current' => true], ['conclusion' => $result['conclusion'], 'current' => $result['current']]);
        $review = $this->stored($result['reviewId']);
        self::assertSame(2, $review->versionNumber);
        self::assertSame('Findings.', $review->summary);
        self::assertTrue($this->document->id?->equals($review->document->id));
        self::assertTrue($this->card->id?->equals($review->card->id));
        self::assertTrue($run->id?->equals($review->workerRunId));
        self::assertTrue($run->workRequestId?->equals($review->workRequestId));
        self::assertSame([AgentReviewSeverity::Important, AgentReviewSeverity::Nit], array_map(static fn ($finding) => $finding->severity, $review->findings()));
        self::assertNull($review->findings()[0]->path);
        self::assertFalse($review->isAnswered());
    }

    public function test_a_review_of_an_older_version_is_stored_and_not_current(): void
    {
        $this->workRun($this->project, $this->card, $this->sessionId, 'design-review');
        $this->sendSession($this->sessionId);

        $result = $this->submit(1, []);

        self::assertSame(['success', false], [$result['conclusion'], $result['current']]);
    }

    public function test_a_review_with_only_nits_and_pre_existing_findings_passes(): void
    {
        $this->workRun($this->project, $this->card, $this->sessionId, 'design-review');
        $this->sendSession($this->sessionId);

        self::assertSame('success', $this->submit(2, [$this->finding('nit'), $this->finding('pre-existing')])['conclusion']);
    }

    public function test_an_important_finding_for_the_owner_does_not_fail_the_review(): void
    {
        $this->workRun($this->project, $this->card, $this->sessionId, 'design-review');
        $this->sendSession($this->sessionId);

        $result = $this->submit(2, [[...$this->finding('important'), 'needsPerson' => true]]);

        self::assertSame('success', $result['conclusion']);
        self::assertTrue($this->stored($result['reviewId'])->findings()[0]->needsPerson);
    }

    public function test_it_refuses_a_call_with_no_session(): void
    {
        $this->workRun($this->project, $this->card, $this->sessionId, 'design-review');

        $this->assertRefused('takes a review only from a running design review worker', fn () => $this->submit(2, []));
    }

    public function test_it_refuses_a_session_of_another_work_kind(): void
    {
        $this->workRun($this->project, $this->card, $this->sessionId, 'tech-design-revise');
        $this->sendSession($this->sessionId);

        $this->assertRefused('takes a review only from a running design review worker', fn () => $this->submit(2, []));
    }

    /** @return iterable<string, array{WorkRequestState}> */
    public static function withdrawnStates(): iterable
    {
        yield 'cancelled' => [WorkRequestState::Cancelled];
        yield 'expired' => [WorkRequestState::Expired];
    }

    #[DataProvider('withdrawnStates')]
    public function test_it_refuses_a_review_from_a_run_whose_work_was_withdrawn(WorkRequestState $state): void
    {
        $this->workRun($this->project, $this->card, $this->sessionId, 'design-review', requestState: $state);
        $this->sendSession($this->sessionId);

        $this->assertRefused('This work was withdrawn.', fn () => $this->submit(2, []));
        self::assertSame([], self::getContainer()->get(DocumentAgentReviewRepository::class)->findOfDocument($this->document));
    }

    public function test_it_takes_a_review_from_a_run_whose_work_is_live(): void
    {
        $this->workRun($this->project, $this->card, $this->sessionId, 'design-review', requestState: WorkRequestState::Claimed);
        $this->sendSession($this->sessionId);

        self::assertSame('success', $this->submit(2, [])['conclusion']);
    }

    public function test_it_refuses_a_run_that_has_ended(): void
    {
        $this->workRun($this->project, $this->card, $this->sessionId, 'design-review', WorkerRunState::Succeeded);
        $this->sendSession($this->sessionId);

        $this->assertRefused('takes a review only from a running design review worker', fn () => $this->submit(2, []));
    }

    public function test_it_refuses_a_document_that_the_card_does_not_link(): void
    {
        $this->workRun($this->project, $this->card, $this->sessionId, 'design-review');
        $this->sendSession($this->sessionId);
        $other = $this->card($this->project, 2);
        $this->em->flush();
        $foreign = $this->linkedDocument($other);

        $this->assertRefused('must name a document that the card links', fn () => ($this->tool)((string) $this->card->id, (string) $foreign->id, 1, 'Summary.', []));
    }

    public function test_it_refuses_a_document_that_is_not_a_draft(): void
    {
        $this->workRun($this->project, $this->card, $this->sessionId, 'design-review');
        $this->sendSession($this->sessionId);
        $this->document->status = DocumentStatus::InReview;
        $this->em->flush();

        $this->assertRefused('is not a draft', fn () => $this->submit(2, []));
    }

    public function test_it_refuses_a_version_the_document_does_not_have(): void
    {
        $this->workRun($this->project, $this->card, $this->sessionId, 'design-review');
        $this->sendSession($this->sessionId);

        $this->assertRefused('must name a version of the document', fn () => $this->submit(3, []));
    }

    public function test_it_refuses_an_empty_summary_and_a_bad_finding(): void
    {
        $this->workRun($this->project, $this->card, $this->sessionId, 'design-review');
        $this->sendSession($this->sessionId);

        $this->assertRefused('summary must not be empty', fn () => ($this->tool)((string) $this->card->id, (string) $this->document->id, 2, '  ', []));
        $this->assertRefused('findings[0].severity must be one of', fn () => $this->submit(2, [[...$this->finding('important'), 'severity' => 'blocker']]));
        $this->assertRefused('findings[0].title must not be empty', fn () => $this->submit(2, [[...$this->finding('important'), 'title' => ' ']]));
        $this->assertRefused('findings[0].needsPerson must be true or false', fn () => $this->submit(2, [[...$this->finding('important'), 'needsPerson' => 'yes']]));
    }

    public function test_it_stores_nothing_when_it_refuses(): void
    {
        $this->workRun($this->project, $this->card, $this->sessionId, 'design-review');

        try {
            $this->submit(2, []);
        } catch (ToolCallException) {
        }

        $repository = self::getContainer()->get(DocumentAgentReviewRepository::class);
        self::assertInstanceOf(DocumentAgentReviewRepository::class, $repository);
        self::assertSame([], $repository->findAll());
    }

    /**
     * @param list<array<string, mixed>> $findings
     *
     * @return array{reviewId: string, conclusion: string, current: bool}
     */
    private function submit(int $version, array $findings): array
    {
        return ($this->tool)((string) $this->card->id, (string) $this->document->id, $version, 'Findings.', $findings);
    }

    /** @return array<string, mixed> */
    private function finding(string $severity): array
    {
        return ['severity' => $severity, 'title' => 'Claim not backed', 'body' => 'The design says the query is indexed, and it is not.'];
    }

    private function stored(string $reviewId): DocumentAgentReview
    {
        $this->em->clear();
        $review = $this->em->find(DocumentAgentReview::class, $reviewId);
        self::assertInstanceOf(DocumentAgentReview::class, $review);

        return $review;
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
