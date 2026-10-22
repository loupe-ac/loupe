<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Workflow;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Workflow\ColumnArrivalFactProvider;
use App\Module\Board\Workflow\ColumnArrivalFacts;
use App\Module\Board\Workflow\DocumentsFactProvider;
use App\Module\Board\Workflow\DocumentsFacts;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\PublishDocumentCommand;
use App\Module\Review\Command\PublishDocumentHandler;
use App\Module\Review\Command\ReviseDocumentCommand;
use App\Module\Review\Command\ReviseDocumentHandler;
use App\Module\Review\Command\SubmitReviewCommand;
use App\Module\Review\Command\SubmitReviewHandler;
use App\Module\Review\Command\UndoVerdictCommand;
use App\Module\Review\Command\UndoVerdictHandler;
use App\Module\Review\Entity\Document;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\DocumentFacts;
use App\Module\Workflow\Contract\FingerprintValue;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** What the documents facts and the column arrival facts say about the entry check, the reviews and the arrival of a card. */
final class DocumentsEntryFactsTest extends KernelTestCase
{
    use ActionScenario;

    private const string DECISIONS = "# Design\n\n<!-- decision: target -->\n\n- ( ) Staging\n- ( ) Production\n\n<!-- /decision -->";

    public function test_a_published_draft_with_nothing_open_has_a_clean_check_on_its_newest_version(): void
    {
        $card = $this->newCard();
        $document = $this->draft($card, "# Design\n\nNothing to decide.");

        $facts = $this->facts($card, $document);
        self::assertNull($facts->publishedAt);
        self::assertNull($facts->cleanCheckVersion);

        $this->service(PublishDocumentHandler::class)(new PublishDocumentCommand($document));
        $facts = $this->facts($card, $document);

        self::assertSame(1, $facts->version);
        self::assertSame(1, $facts->cleanCheckVersion);
        self::assertSame(0, $facts->entryUnansweredDecisions);
        self::assertSame(0, $facts->entryOpenThreads);
        self::assertNotNull($facts->publishedAt);
        self::assertTrue($facts->hasEntryCheck());
        self::assertNull($facts->newestReviewId);
        self::assertFalse($facts->rejectedBefore);
    }

    public function test_an_open_decision_makes_the_check_unclean(): void
    {
        $card = $this->newCard();
        $document = $this->draft($card, self::DECISIONS);
        $this->service(PublishDocumentHandler::class)(new PublishDocumentCommand($document));

        $facts = $this->facts($card, $document);

        self::assertNull($facts->cleanCheckVersion);
        self::assertSame(1, $facts->entryUnansweredDecisions);
        self::assertTrue($facts->hasEntryCheck());
    }

    public function test_a_revision_of_a_document_in_review_leaves_the_new_version_with_no_check(): void
    {
        $card = $this->newCard();
        $document = $this->draft($card, "# Design\n\nNothing to decide.");
        $this->service(PublishDocumentHandler::class)(new PublishDocumentCommand($document));

        $this->service(ReviseDocumentHandler::class)(new ReviseDocumentCommand($document, "# Design\n\nA second round.", 'Second round.'));
        $facts = $this->facts($card, $document);

        self::assertSame(2, $facts->version);
        self::assertNull($facts->cleanCheckVersion);
        self::assertNull($facts->publishedAt);
        self::assertNull($facts->entryUnansweredDecisions);
        self::assertFalse($facts->hasEntryCheck());
    }

    public function test_the_newest_review_of_the_newest_version_and_any_change_request_or_withdrawal_show(): void
    {
        $card = $this->newCard();
        $document = $this->draft($card, "# Design\n\nNothing to decide.");
        $this->service(PublishDocumentHandler::class)(new PublishDocumentCommand($document));

        $approval = $this->service(SubmitReviewHandler::class)(new SubmitReviewCommand($document->owner, $document, 'approved', 1, 'Fine.'));
        $facts = $this->facts($card, $document);
        self::assertSame($approval->id?->toRfc4122(), $facts->newestReviewId);
        self::assertFalse($facts->rejectedBefore, 'An approval is no rejection.');

        $this->service(UndoVerdictHandler::class)(new UndoVerdictCommand($document, $document->owner, $approval->id?->toRfc4122() ?? ''));
        $facts = $this->facts($card, $document);
        $withdrawalId = $this->em()->getConnection()->fetchOne('SELECT id FROM reviews WHERE verdict = ?', ['withdrawn']);
        self::assertSame($withdrawalId, $facts->newestReviewId, 'A withdrawal is a row of the log.');
        self::assertTrue($facts->rejectedBefore);

        $this->service(ReviseDocumentHandler::class)(new ReviseDocumentCommand($document, "# Design\n\nA second round.", 'Second round.'));
        $facts = $this->facts($card, $document);
        self::assertNull($facts->newestReviewId, 'The new version has no review yet.');
        self::assertTrue($facts->rejectedBefore, 'The withdrawal stays on record for the document.');
    }

    public function test_the_withdrawal_of_an_approval_shows_only_while_it_is_the_newest_review_of_the_newest_version(): void
    {
        $card = $this->newCard();
        $document = $this->draft($card, "# Design\n\nNothing to decide.");
        $this->service(PublishDocumentHandler::class)(new PublishDocumentCommand($document));
        self::assertFalse($this->facts($card, $document)->newestReviewWithdrewApproval, 'No review yet.');

        $approval = $this->service(SubmitReviewHandler::class)(new SubmitReviewCommand($document->owner, $document, 'approved', 1, 'Fine.'));
        self::assertFalse($this->facts($card, $document)->newestReviewWithdrewApproval, 'An approval is no withdrawal.');

        $withdrawal = $this->service(UndoVerdictHandler::class)(new UndoVerdictCommand($document, $document->owner, $approval->id?->toRfc4122() ?? ''));
        self::assertTrue($this->facts($card, $document)->newestReviewWithdrewApproval);

        $this->service(SubmitReviewHandler::class)(new SubmitReviewCommand($document->owner, $document, 'changes-requested', 1, 'Rework it.', expectedReviewId: $withdrawal->id?->toRfc4122()));
        self::assertFalse($this->facts($card, $document)->newestReviewWithdrewApproval, 'A later review replaces the withdrawal as the newest.');
    }

    public function test_the_withdrawal_of_a_change_request_is_no_withdrawal_of_an_approval(): void
    {
        $card = $this->newCard();
        $document = $this->draft($card, "# Design\n\nNothing to decide.");
        $this->service(PublishDocumentHandler::class)(new PublishDocumentCommand($document));
        $request = $this->service(SubmitReviewHandler::class)(new SubmitReviewCommand($document->owner, $document, 'changes-requested', 1, 'Rework it.'));

        $this->service(UndoVerdictHandler::class)(new UndoVerdictCommand($document, $document->owner, $request->id?->toRfc4122() ?? ''));

        $facts = $this->facts($card, $document);
        self::assertTrue($facts->rejectedBefore);
        self::assertFalse($facts->newestReviewWithdrewApproval);
    }

    public function test_a_revision_ends_the_withdrawal_of_an_approval(): void
    {
        $card = $this->newCard();
        $document = $this->draft($card, "# Design\n\nNothing to decide.");
        $this->service(PublishDocumentHandler::class)(new PublishDocumentCommand($document));
        $approval = $this->service(SubmitReviewHandler::class)(new SubmitReviewCommand($document->owner, $document, 'approved', 1, 'Fine.'));
        $this->service(UndoVerdictHandler::class)(new UndoVerdictCommand($document, $document->owner, $approval->id?->toRfc4122() ?? ''));
        self::assertTrue($this->facts($card, $document)->newestReviewWithdrewApproval);

        $this->service(ReviseDocumentHandler::class)(new ReviseDocumentCommand($document, "# Design\n\nA second round.", 'Second round.'));

        self::assertFalse($this->facts($card, $document)->newestReviewWithdrewApproval);
    }

    public function test_a_change_request_on_an_older_version_still_counts_and_the_revision_has_no_clean_check(): void
    {
        $card = $this->newCard();
        $document = $this->draft($card, "# Design\n\nNothing to decide.");
        $this->service(PublishDocumentHandler::class)(new PublishDocumentCommand($document));
        $this->service(SubmitReviewHandler::class)(new SubmitReviewCommand($document->owner, $document, 'changes-requested', 1, 'Rework it.'));
        $this->service(ReviseDocumentHandler::class)(new ReviseDocumentCommand($document, "# Design\n\nReworked.", 'Reworked.'));

        $facts = $this->facts($card, $document);

        self::assertTrue($facts->rejectedBefore);
        self::assertNull($facts->cleanCheckVersion, 'A revision of a changes-requested document is no publish.');
    }

    public function test_the_fingerprint_changes_with_each_part_of_the_entry_facts(): void
    {
        $at = new \DateTimeImmutable('2026-10-22 10:00:00');
        $base = new DocumentFacts(['d'], 'in-review', 'one', 'T', null, 1, 1, 'review', $at, 0, 0, false);
        $variants = [
            'version' => new DocumentFacts(['d'], 'in-review', 'one', 'T', null, 2, 1, 'review', $at, 0, 0, false),
            'clean check' => new DocumentFacts(['d'], 'in-review', 'one', 'T', null, 1, null, 'review', $at, 0, 0, false),
            'newest review' => new DocumentFacts(['d'], 'in-review', 'one', 'T', null, 1, 1, 'other', $at, 0, 0, false),
            'entry time' => new DocumentFacts(['d'], 'in-review', 'one', 'T', null, 1, 1, 'review', $at->modify('+1 minute'), 0, 0, false),
            'decisions' => new DocumentFacts(['d'], 'in-review', 'one', 'T', null, 1, 1, 'review', $at, 1, 0, false),
            'threads' => new DocumentFacts(['d'], 'in-review', 'one', 'T', null, 1, 1, 'review', $at, 0, 1, false),
            'rejection' => new DocumentFacts(['d'], 'in-review', 'one', 'T', null, 1, 1, 'review', $at, 0, 0, true),
            'withdrawn approval' => new DocumentFacts(['d'], 'in-review', 'one', 'T', null, 1, 1, 'review', $at, 0, 0, false, true),
        ];

        $reference = FingerprintValue::documents([$base]);
        self::assertSame($reference, FingerprintValue::documents([$base]));
        foreach ($variants as $name => $variant) {
            self::assertNotSame($reference, FingerprintValue::documents([$variant]), $name);
        }
        json_encode($reference, \JSON_THROW_ON_ERROR);
    }

    public function test_the_arrival_of_a_card_is_the_newest_created_or_moved_row(): void
    {
        $card = $this->newCard();
        $this->event($card, CardEventKind::Created, '2026-10-22 09:00:00');
        $this->event($card, CardEventKind::Moved, '2026-10-22 10:00:00');
        $this->event($card, CardEventKind::Moved, '2026-10-22 09:30:00');
        $this->event($card, CardEventKind::FixRequested, '2026-10-22 11:00:00');
        $provider = $this->service(ColumnArrivalFactProvider::class);

        $facts = $provider->build($card->snapshot());

        self::assertInstanceOf(ColumnArrivalFacts::class, $facts);
        self::assertEquals(new \DateTimeImmutable('2026-10-22 10:00:00'), $facts->arrivedAt);
        self::assertNotSame($provider->fingerprint($facts), $provider->fingerprint(new ColumnArrivalFacts(new \DateTimeImmutable('2026-10-22 10:01:00'))));
        self::assertTrue($provider->isOn());
    }

    public function test_a_card_with_no_created_or_moved_row_has_no_arrival(): void
    {
        $card = $this->newCard();
        $this->event($card, CardEventKind::FixRequested, '2026-10-22 11:00:00');
        $provider = $this->service(ColumnArrivalFactProvider::class);

        $facts = $provider->build($card->snapshot());

        self::assertInstanceOf(ColumnArrivalFacts::class, $facts);
        self::assertNull($facts->arrivedAt);
        self::assertNull($provider->fingerprint($facts));
    }

    private function newCard(): Card
    {
        self::bootKernel();

        return $this->card($this->workflowProject('entry-facts'), 'tech-design');
    }

    private function draft(Card $card, string $markdown): Document
    {
        $document = $this->service(CreateDocumentHandler::class)(new CreateDocumentCommand($card->project, 'Tech design', $markdown, tagNames: ['tech-design'], draft: true));
        $this->em()->persist(new CardDocument($card, $document));
        $this->em()->flush();

        return $document;
    }

    private function facts(Card $card, Document $document): DocumentFacts
    {
        $facts = $this->service(DocumentsFactProvider::class)->build($card->snapshot());
        self::assertInstanceOf(DocumentsFacts::class, $facts);
        $matching = array_values(array_filter($facts->documents, static fn (DocumentFacts $candidate): bool => $candidate->id === (string) $document->id));
        self::assertCount(1, $matching);

        return $matching[0];
    }

    private function event(Card $card, CardEventKind $kind, string $at): void
    {
        $this->service(CardEventRepository::class)->record($card, $kind, Actor::System, null, [], new \DateTimeImmutable($at));
        $this->em()->flush();
    }
}
