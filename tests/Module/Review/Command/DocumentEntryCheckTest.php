<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Command;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\AddCommentCommand;
use App\Module\Review\Command\AddCommentHandler;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\PublishDocumentCommand;
use App\Module\Review\Command\PublishDocumentHandler;
use App\Module\Review\Command\ResolveCommentCommand;
use App\Module\Review\Command\ResolveCommentHandler;
use App\Module\Review\Command\ReviseDocumentCommand;
use App\Module\Review\Command\ReviseDocumentHandler;
use App\Module\Review\Command\SaveDecisionAnswerCommand;
use App\Module\Review\Command\SaveDecisionAnswerHandler;
use App\Module\Review\Command\SubmitReviewCommand;
use App\Module\Review\Command\SubmitReviewHandler;
use App\Module\Review\Command\UndoVerdictCommand;
use App\Module\Review\Command\UndoVerdictHandler;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\CommentStatus;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The entry check that a document version records when its document is published, which moves it out of Draft. */
final class DocumentEntryCheckTest extends KernelTestCase
{
    private const string MARKDOWN = "# Design\n\nThe quoted passage.\n\n<!-- decision: target -->\n\n- ( ) Staging\n- ( ) Production\n\n<!-- /decision -->\n\n<!-- decision: region -->\n\n- ( ) Europe\n- ( ) America\n\n<!-- /decision -->";

    public function test_publishing_a_draft_records_the_decisions_and_threads_that_stand_at_that_moment(): void
    {
        $document = $this->document(draft: true);
        $this->answer($document, 'target');
        $this->comment($document);
        $this->assertNoCheck($document, 1);
        $before = new \DateTimeImmutable();

        self::assertTrue($this->service(PublishDocumentHandler::class)(new PublishDocumentCommand($document)));

        $this->assertCheck($document, 1, unanswered: 1, threads: 1, notBefore: $before);
    }

    public function test_publishing_a_draft_with_nothing_open_records_a_clean_check(): void
    {
        $document = $this->document(draft: true, markdown: "# Design\n\nNo decision here.");

        $this->service(PublishDocumentHandler::class)(new PublishDocumentCommand($document));

        $this->assertCheck($document, 1, unanswered: 0, threads: 0);
    }

    public function test_a_resolved_thread_does_not_count_and_a_pending_and_an_addressed_thread_do(): void
    {
        $document = $this->document(draft: true, markdown: "# Design\n\nThe quoted passage.");
        $resolved = $this->comment($document);
        $this->service(ResolveCommentHandler::class)(new ResolveCommentCommand($resolved));
        $addressed = $this->comment($document);
        $addressed->status = CommentStatus::Addressed;
        $this->comment($document);
        $this->em()->flush();

        $this->service(PublishDocumentHandler::class)(new PublishDocumentCommand($document));

        $this->assertCheck($document, 1, unanswered: 0, threads: 2);
    }

    public function test_a_document_created_in_review_or_as_a_draft_records_none(): void
    {
        $inReview = $this->document(draft: false, markdown: "# Design\n\nNo decision here.");
        $draft = $this->document(draft: true);

        $this->assertNoCheck($inReview, 1);
        $this->assertNoCheck($draft, 1);
    }

    public function test_a_revision_of_a_published_document_records_none(): void
    {
        $document = $this->published();

        $this->revise($document);

        $this->assertCheck($document, 1, unanswered: 2, threads: 0);
        $this->assertNoCheck($document, 2);
    }

    public function test_a_revision_of_a_draft_records_none_and_the_publish_that_follows_records_one(): void
    {
        $document = $this->document(draft: true);

        $this->revise($document);
        $this->assertNoCheck($document, 2);
        $this->service(PublishDocumentHandler::class)(new PublishDocumentCommand($document));

        $this->assertNoCheck($document, 1);
        $this->assertCheck($document, 2, unanswered: 2, threads: 0);
    }

    public function test_a_revision_of_a_changes_requested_document_is_no_publish_and_records_none(): void
    {
        $document = $this->published();
        $this->comment($document);
        $this->service(SubmitReviewHandler::class)(new SubmitReviewCommand($document->owner, $document, 'changes-requested', 1, 'Rework it.'));
        self::assertSame(DocumentStatus::ChangesRequested, $document->status);

        $this->revise($document);

        self::assertSame(DocumentStatus::InReview, $document->status);
        $this->assertCheck($document, 1, unanswered: 2, threads: 0);
        $this->assertNoCheck($document, 2);
    }

    public function test_a_revision_of_an_approved_document_is_no_publish_and_records_none(): void
    {
        $document = $this->published("# Design\n\nNo decision here.");
        $this->service(SubmitReviewHandler::class)(new SubmitReviewCommand($document->owner, $document, 'approved', 1, 'Fine.'));
        self::assertSame(DocumentStatus::Approved, $document->status);

        $this->revise($document, "# Design\n\nStill no decision here.");

        self::assertSame(DocumentStatus::InReview, $document->status);
        $this->assertCheck($document, 1, unanswered: 0, threads: 0);
        $this->assertNoCheck($document, 2);
    }

    public function test_an_undo_is_not_a_publish(): void
    {
        $document = $this->published("# Design\n\nNo decision here.");
        $this->service(SubmitReviewHandler::class)(new SubmitReviewCommand($document->owner, $document, 'approved', 1, 'Fine.'));
        $review = $this->em()->getConnection()->fetchOne('SELECT id FROM reviews');
        self::assertIsString($review);
        $before = $this->version($document, 1)['published_at'];
        self::assertNotNull($before);

        $this->service(UndoVerdictHandler::class)(new UndoVerdictCommand($document, $document->owner, $review));

        self::assertSame(DocumentStatus::InReview, $document->status);
        self::assertSame($before, $this->version($document, 1)['published_at']);
        $this->assertCheck($document, 1, unanswered: 0, threads: 0);
    }

    private function document(bool $draft, string $markdown = self::MARKDOWN): Document
    {
        self::bootKernel();
        $owner = new User(fullName: 'Reviewer', email: 'entry-'.uniqid().'@example.com', password: 'hashed-placeholder');
        $this->em()->persist($owner);
        $project = new Project($owner, 'p-'.uniqid());
        $this->em()->persist($project);
        $this->em()->flush();

        return $this->service(CreateDocumentHandler::class)(new CreateDocumentCommand($project, 'Design', $markdown, draft: $draft));
    }

    private function published(string $markdown = self::MARKDOWN): Document
    {
        $document = $this->document(draft: true, markdown: $markdown);
        $this->service(PublishDocumentHandler::class)(new PublishDocumentCommand($document));

        return $document;
    }

    private function revise(Document $document, string $markdown = self::MARKDOWN): void
    {
        $this->service(ReviseDocumentHandler::class)(new ReviseDocumentCommand($document, $markdown, 'Another round.'));
    }

    private function answer(Document $document, string $decisionId): void
    {
        $this->service(SaveDecisionAnswerHandler::class)(new SaveDecisionAnswerCommand($document, $decisionId, 1, [1], null, false, $document->owner));
    }

    private function comment(Document $document): Comment
    {
        return $this->service(AddCommentHandler::class)(new AddCommentCommand($document->owner, $document, 1, 'The quoted passage.', '', '', 'Look again.'));
    }

    private function assertCheck(Document $document, int $number, int $unanswered, int $threads, ?\DateTimeImmutable $notBefore = null): void
    {
        $version = $this->version($document, $number);
        self::assertNotNull($version['published_at']);
        self::assertSame($unanswered, $version['entry_unanswered_decisions']);
        self::assertSame($threads, $version['entry_open_threads']);
        if (null !== $notBefore) {
            self::assertGreaterThanOrEqual($notBefore->getTimestamp(), new \DateTimeImmutable((string) $version['published_at'])->getTimestamp());
        }
    }

    private function assertNoCheck(Document $document, int $number): void
    {
        $version = $this->version($document, $number);
        self::assertNull($version['published_at']);
        self::assertNull($version['entry_unanswered_decisions']);
        self::assertNull($version['entry_open_threads']);
    }

    /** @return array<string, mixed> */
    private function version(Document $document, int $number): array
    {
        $row = $this->em()->getConnection()->fetchAssociative(
            'SELECT published_at, entry_unanswered_decisions, entry_open_threads FROM document_versions WHERE document_id = ? AND version_number = ?',
            [(string) $document->id, $number],
        );
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }

    private function em(): EntityManagerInterface
    {
        return $this->service(EntityManagerInterface::class);
    }
}
