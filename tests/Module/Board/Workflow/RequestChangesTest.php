<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Workflow;

use App\Module\Account\Repository\UserRepository;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Workflow\RequestChanges;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\ReviseDocumentCommand;
use App\Module\Review\Command\ReviseDocumentHandler;
use App\Module\Review\Command\SubmitReviewCommand;
use App\Module\Review\Command\SubmitReviewHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Repository\DocumentRepository;
use App\Module\Review\Repository\ReviewRepository;
use App\Module\Workflow\Contract\ActionOutcome;
use App\Module\Workflow\Contract\DocumentFacts;
use App\Tests\Module\Workflow\Action\ActionScenario;
use App\Tests\Module\Workflow\Fact\FactsMother;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class RequestChangesTest extends KernelTestCase
{
    use ActionScenario;

    private const string NOTE_KEY = 'workflow.refusal.move-refused';

    public function test_it_requests_changes_on_an_in_review_and_on_an_approved_document_with_the_translated_note(): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('changes-both'), 'tech-design');
        $inReview = $this->document($card, ['tech-design'], DocumentStatus::InReview);
        $approved = $this->document($card, ['deploy-notes'], DocumentStatus::InReview);
        $approval = $this->service(SubmitReviewHandler::class)(new SubmitReviewCommand($card->project->owner, $approved, 'approved', 1));

        $outcome = $this->requestChanges($card, [
            $this->facts($inReview, null),
            $this->facts($approved, (string) $approval->id),
        ], ['tech-design', 'deploy-notes']);

        self::assertEquals(ActionOutcome::done(), $outcome);
        $this->em()->flush();
        $this->em()->clear();
        $reviews = $this->service(ReviewRepository::class)->findBy(['workflowRule' => 'test-rule']);
        self::assertCount(2, $reviews);
        foreach ($reviews as $review) {
            self::assertSame('changes-requested', $review->verdict->value);
            self::assertSame('The board refused the move.', $review->note);
        }
        self::assertSame(DocumentStatus::ChangesRequested, $this->em()->find(Document::class, $inReview->id)?->status);
        self::assertSame(DocumentStatus::ChangesRequested, $this->em()->find(Document::class, $approved->id)?->status);
    }

    public function test_it_leaves_a_draft_and_a_document_with_another_tag(): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('changes-skip'), 'tech-design');
        $draft = $this->document($card, ['tech-design'], DocumentStatus::Draft);
        $other = $this->document($card, ['notes'], DocumentStatus::InReview);

        $outcome = $this->requestChanges($card, [$this->facts($draft, null), $this->facts($other, null)], ['tech-design']);

        self::assertEquals(ActionOutcome::done(), $outcome);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM reviews'));
    }

    public function test_a_revision_after_the_facts_were_read_is_a_refusal_and_the_document_is_unchanged(): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('changes-stale'), 'tech-design');
        $document = $this->document($card, ['tech-design'], DocumentStatus::InReview);
        $facts = $this->facts($document, null);
        $this->service(ReviseDocumentHandler::class)(new ReviseDocumentCommand($document, '# Design, revised', 'A new round.'));
        $document->status = DocumentStatus::InReview;
        $this->em()->flush();

        $outcome = $this->requestChanges($card, [$facts], ['tech-design']);

        self::assertEquals(ActionOutcome::refused('document-verdict-refused'), $outcome);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM reviews'));
        self::assertSame(DocumentStatus::InReview, $document->status);
    }

    /** @param list<string> $tags */
    private function document(Card $card, array $tags, DocumentStatus $status): Document
    {
        $document = $this->service(CreateDocumentHandler::class)(new CreateDocumentCommand($card->project, 'Tech design', '# Design', tagNames: $tags));
        $document->status = $status;
        $this->em()->persist(new CardDocument($card, $document));
        $this->em()->flush();

        return $document;
    }

    private function facts(Document $document, ?string $newestReviewId): DocumentFacts
    {
        return new DocumentFacts(
            array_values($document->tags->map(static fn ($tag): string => $tag->name)->toArray()),
            $document->status->value,
            (string) $document->id,
            $document->title,
            null,
            1,
            null,
            $newestReviewId,
        );
    }

    /**
     * @param list<DocumentFacts> $documents
     * @param list<string>        $tags
     */
    private function requestChanges(Card $card, array $documents, array $tags): ActionOutcome
    {
        $rule = $this->rule('request-changes', ['note' => self::NOTE_KEY], lists: ['tags' => $tags]);

        return $this->runAction(
            new RequestChanges(
                $this->service(DocumentRepository::class),
                $this->service(UserRepository::class),
                $this->service(SubmitReviewHandler::class),
                $this->service(TranslatorInterface::class),
            ),
            $rule,
            $card->snapshot(),
            FactsMother::facts(card: FactsMother::card(documents: $documents)),
            $this->state($card, $rule->id),
        );
    }
}
