<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Workflow;

use App\Module\Account\Repository\UserRepository;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Entity\CardEvent;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Workflow\ApproveDocument;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\ReviseDocumentCommand;
use App\Module\Review\Command\ReviseDocumentHandler;
use App\Module\Review\Command\SubmitReviewCommand;
use App\Module\Review\Command\SubmitReviewHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Review;
use App\Module\Review\Repository\DocumentRepository;
use App\Module\Workflow\Contract\ActionOutcome;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\DocumentFacts;
use App\Tests\Module\Workflow\Action\ActionScenario;
use App\Tests\Module\Workflow\Fact\FactsMother;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ApproveDocumentTest extends KernelTestCase
{
    use ActionScenario;

    public function test_it_approves_a_document_whose_newest_version_has_a_clean_check(): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('approve-clean'), 'tech-design');
        $document = $this->document($card, ['tech-design']);
        $inboxItems = $this->rowCount('inbox_items');

        $outcome = $this->approve($card, [$this->facts($document, cleanCheckVersion: 1)], 'tech-design');

        self::assertEquals(ActionOutcome::done(), $outcome);
        $review = $this->onlyReview($document);
        self::assertSame('approved', $review->verdict->value);
        self::assertSame('test-rule', $review->workflowRule);
        self::assertSame($this->service(UserRepository::class)->workflow()->id?->toRfc4122(), $review->reviewer->id?->toRfc4122());
        self::assertNull($review->note);
        self::assertSame(DocumentStatus::Approved, $this->reload($document)->status);
        self::assertSame($inboxItems, $this->rowCount('inbox_items'), 'The approval opens no inbox item.');

        $rows = $this->rows($card, CardEventKind::DocumentVerdict);
        self::assertCount(1, $rows);
        self::assertSame(Actor::System, $rows[0]->actorKind);
        self::assertSame('approved', $rows[0]->detail['verdict']);
        self::assertSame('test-rule', $rows[0]->detail['rule']);

        $payload = json_decode((string) $this->em()->getConnection()->fetchOne("SELECT payload FROM outbox_events WHERE type = 'document.review_submitted' AND project_id = ?", [(string) $card->project->id]), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('system', $payload['actor']);
    }

    public function test_it_leaves_a_document_with_no_clean_check_with_another_tag_or_in_another_status(): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('approve-skip'), 'tech-design');
        $unchecked = $this->document($card, ['tech-design']);
        $other = $this->document($card, ['notes']);
        $draft = $this->document($card, ['tech-design'], DocumentStatus::Draft);

        $outcome = $this->approve($card, [
            $this->facts($unchecked, cleanCheckVersion: null),
            $this->facts($other, cleanCheckVersion: 1),
            $this->facts($draft, cleanCheckVersion: 1),
        ], 'tech-design');

        self::assertEquals(ActionOutcome::done(), $outcome);
        self::assertSame(0, $this->rowCount('reviews'));
        self::assertSame([], $this->rows($card, CardEventKind::VerdictFailed));
    }

    public function test_a_revision_after_the_facts_were_read_refuses_the_approval_and_writes_a_failed_row(): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('approve-stale'), 'tech-design');
        $document = $this->document($card, ['tech-design']);
        $facts = $this->facts($document, cleanCheckVersion: 1);
        $revise = $this->service(ReviseDocumentHandler::class);
        $revise(new ReviseDocumentCommand($document, '# Design, revised', 'A new round.'));
        $document->status = DocumentStatus::InReview;
        $this->em()->flush();

        $outcome = $this->approve($card, [$facts], 'tech-design');

        self::assertEquals(ActionOutcome::done(), $outcome);
        self::assertSame(DocumentStatus::InReview, $this->reload($document)->status);
        self::assertSame(0, $this->rowCount('reviews'));
        $rows = $this->rows($card, CardEventKind::VerdictFailed);
        self::assertCount(1, $rows);
        self::assertSame('verdict_stale', $rows[0]->detail['refusal']);
        self::assertSame('test-rule', $rows[0]->detail['rule']);
        self::assertSame((string) $document->id, $rows[0]->detail['documentId']);
        self::assertSame([], $this->rows($card, CardEventKind::DocumentVerdict));
        $card = $this->service(CardRepository::class)->find($card->id) ?? throw new \LogicException('The card exists.');
        self::assertSame('tech-design', $card->column->slug);
    }

    public function test_a_verdict_that_landed_after_the_facts_were_read_refuses_the_approval(): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('approve-changed'), 'tech-design');
        $document = $this->document($card, ['tech-design']);
        $facts = $this->facts($document, cleanCheckVersion: 1);
        $this->service(SubmitReviewHandler::class)(new SubmitReviewCommand($card->project->owner, $document, 'changes-requested', 1, 'Rework it.'));
        $document->status = DocumentStatus::InReview;
        $this->em()->flush();

        $outcome = $this->approve($card, [$facts], 'tech-design');

        self::assertEquals(ActionOutcome::done(), $outcome);
        $rows = $this->rows($card, CardEventKind::VerdictFailed);
        self::assertCount(1, $rows);
        self::assertSame('verdict_changed', $rows[0]->detail['refusal']);
        self::assertSame(1, $this->rowCount('reviews'));
    }

    /** @param list<string> $tags */
    private function document(Card $card, array $tags, DocumentStatus $status = DocumentStatus::InReview): Document
    {
        $document = $this->service(CreateDocumentHandler::class)(new CreateDocumentCommand($card->project, 'Tech design', '# Design', tagNames: $tags));
        $document->status = $status;
        $this->em()->persist(new CardDocument($card, $document));
        $this->em()->flush();

        return $document;
    }

    private function facts(Document $document, ?int $cleanCheckVersion): DocumentFacts
    {
        return new DocumentFacts(
            array_values($document->tags->map(static fn ($tag): string => $tag->name)->toArray()),
            $document->status->value,
            (string) $document->id,
            $document->title,
            null,
            1,
            $cleanCheckVersion,
            null,
        );
    }

    /** @param list<DocumentFacts> $documents */
    private function approve(Card $card, array $documents, string $tag): ActionOutcome
    {
        $rule = $this->rule('approve-document', ['tag' => $tag]);

        return $this->runAction(
            new ApproveDocument(
                $this->service(CardRepository::class),
                $this->service(DocumentRepository::class),
                $this->service(UserRepository::class),
                $this->service(SubmitReviewHandler::class),
                $this->service(CardEventRepository::class),
            ),
            $rule,
            $card->snapshot(),
            FactsMother::facts(card: FactsMother::card(documents: $documents)),
            $this->state($card, $rule->id),
        );
    }

    private function onlyReview(Document $document): Review
    {
        $reviews = $this->em()->getRepository(Review::class)->findAll();
        self::assertCount(1, $reviews);

        return $reviews[0];
    }

    private function reload(Document $document): Document
    {
        $this->em()->flush();
        $this->em()->clear();

        return $this->em()->find(Document::class, $document->id) ?? throw new \LogicException('The document exists.');
    }

    private function rowCount(string $table): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
    }

    /** @return list<CardEvent> */
    private function rows(Card $card, CardEventKind $kind): array
    {
        // The engine flushes at the end of its pass.
        $this->em()->flush();
        $this->em()->clear();

        return $this->service(CardEventRepository::class)->findBy(['card' => $card->id, 'kind' => $kind]);
    }
}
