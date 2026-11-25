<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Command;

use App\Api\Problem\ApiProblemException;
use App\Exception\DomainErrors;
use App\Module\Review\Command\SubmitReviewCommand;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Verdict;
use App\Module\Review\Event\ReviewSubmitted;
use App\Module\Review\Mcp\DocumentToolErrors;
use App\Module\Review\Mcp\ToolCallErrorMessages;
use App\Module\Review\Service\DocumentApiProblems;
use App\Tests\Module\Review\Mcp\DecisionDocumentScenario;
use App\Tests\Module\Review\Support\BuildsGuardedSubmitReview;
use App\Tests\Module\Review\Support\StubVerdictGuard;
use App\Tests\Support\RecordingAuditor;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class SubmitReviewVerdictGuardTest extends KernelTestCase
{
    use BuildsGuardedSubmitReview;
    use DecisionDocumentScenario;

    /** A key that no map of the Review module holds, as the key of a guard from another module. */
    private const string KEY = 'inbox.review.error.not_a_review';

    private const string SENTENCE = 'This request does not accept a review verdict.';

    private RecordingAuditor $audit;
    private int $submitted = 0;

    protected function setUp(): void
    {
        $this->bootScenario();
        $this->audit = RecordingAuditor::installedIn(self::getContainer());
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(ReviewSubmitted::class, function (): void {
            ++$this->submitted;
        });
    }

    public function test_a_refusal_records_no_verdict(): void
    {
        $document = $this->decisionDocument();
        $guard = new StubVerdictGuard(self::KEY);
        $this->audit->forget();

        $refusal = $this->refusal($document, 'changes-requested', $guard);

        self::assertSame(['verdict' => self::KEY], $refusal->errors);
        self::assertTrue($this->em->isOpen());
        self::assertSame([[(string) $document->id, Verdict::ChangesRequested]], $guard->calls);
        self::assertSame(0, $this->storedReviews($document));
        self::assertSame(DocumentStatus::InReview, $document->status);
        self::assertSame('in-review', $this->storedStatus($document));
        self::assertSame([], $this->audit->operations());
        self::assertSame(0, $this->submitted);
    }

    public function test_a_guard_that_returns_null_lets_the_verdict_through(): void
    {
        $document = $this->decisionDocument();
        $guard = new StubVerdictGuard(null);
        $this->audit->forget();

        $this->guardedSubmitReview($guard)(new SubmitReviewCommand($document->owner, $document, 'changes-requested', 1, 'Rework it.'));

        self::assertSame([[(string) $document->id, Verdict::ChangesRequested]], $guard->calls);
        self::assertSame(1, $this->storedReviews($document));
        self::assertSame('changes-requested', $this->storedStatus($document));
        self::assertSame(['review.document_verdict_submitted'], $this->audit->operations());
        self::assertSame(1, $this->submitted);
    }

    public function test_the_first_refusal_wins_and_comes_before_the_decision_gate(): void
    {
        $document = $this->decisionDocument();
        $open = new StubVerdictGuard(null);
        $first = new StubVerdictGuard(self::KEY);
        $second = new StubVerdictGuard('inbox.review.error.not_a_document');

        $refusal = $this->refusal($document, 'approved', $open, $first, $second);

        self::assertSame(['verdict' => self::KEY], $refusal->errors);
        self::assertSame([[(string) $document->id, Verdict::Approved]], $open->calls);
        self::assertSame([], $second->calls);
    }

    public function test_a_stale_version_is_refused_before_any_guard_is_asked(): void
    {
        $document = $this->decisionDocument();
        $this->reviseDocument($document);
        $guard = new StubVerdictGuard(self::KEY);

        $refusal = $this->refusal($document, 'approved', $guard);

        self::assertSame(['versionNumber' => 'review.document.flash.verdict_stale'], $refusal->errors);
        self::assertSame([], $guard->calls);
    }

    public function test_the_public_api_answers_a_refusal_with_a_coded_conflict(): void
    {
        $document = $this->decisionDocument();
        $refusal = $this->refusal($document, 'approved', new StubVerdictGuard(self::KEY));
        $problems = self::getContainer()->get(DocumentApiProblems::class);
        self::assertInstanceOf(DocumentApiProblems::class, $problems);

        $answer = $problems->from($refusal);

        self::assertInstanceOf(ApiProblemException::class, $answer);
        self::assertSame(409, $answer->problem->status);
        self::assertSame('verdict_refused', $answer->problem->code);
        self::assertSame('Verdict refused', $answer->problem->title);
        self::assertSame(self::SENTENCE, $answer->problem->detail);
        self::assertSame($refusal, $answer->getPrevious());
    }

    public function test_an_agent_reads_the_sentence_of_a_refusal(): void
    {
        $document = $this->decisionDocument();
        $refusal = $this->refusal($document, 'approved', new StubVerdictGuard(self::KEY));

        $answer = $this->toolErrors()->forDocument($refusal, $document);

        self::assertSame('verdict: '.self::SENTENCE, $answer->getMessage());
        self::assertSame($refusal, $answer->getPrevious());
    }

    public function test_the_same_key_from_outside_a_guard_stays_unmapped(): void
    {
        $document = $this->decisionDocument();
        $errors = new DomainErrors(['verdict' => self::KEY]);
        $problems = self::getContainer()->get(DocumentApiProblems::class);
        self::assertInstanceOf(DocumentApiProblems::class, $problems);

        self::assertSame($errors, $problems->from($errors));
        self::assertSame('verdict: '.ToolCallErrorMessages::UNMAPPED, $this->toolErrors()->forDocument($errors, $document)->getMessage());
    }

    private function toolErrors(): DocumentToolErrors
    {
        $errors = self::getContainer()->get(DocumentToolErrors::class);
        self::assertInstanceOf(DocumentToolErrors::class, $errors);

        return $errors;
    }

    private function refusal(Document $document, string $verdict, StubVerdictGuard ...$guards): DomainErrors
    {
        try {
            $this->guardedSubmitReview(...$guards)(new SubmitReviewCommand($document->owner, $document, $verdict, 1, 'A note.'));
        } catch (DomainErrors $refusal) {
            return $refusal;
        }

        self::fail('The verdict was not refused.');
    }

    private function storedReviews(Document $document): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM reviews r JOIN document_versions v ON v.id = r.version_id WHERE v.document_id = ?', [(string) $document->id]);
    }

    private function storedStatus(Document $document): mixed
    {
        return $this->em->getConnection()->fetchOne('SELECT status FROM documents WHERE id = ?', [(string) $document->id]);
    }
}
