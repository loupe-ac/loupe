<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Workflow\Condition;

use App\Module\Board\Workflow\ColumnArrivalFacts;
use App\Module\Board\Workflow\Condition\CardDocumentEnteredReviewInColumn;
use App\Module\Board\Workflow\Condition\CardDocumentEntryDecisionsOpen;
use App\Module\Board\Workflow\Condition\CardDocumentEntryThreadsOpen;
use App\Module\Board\Workflow\Condition\CardDocumentRejectedBefore;
use App\Module\Board\Workflow\DocumentsFacts;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\DocumentFacts;
use App\Module\Workflow\Contract\Facts;
use App\Tests\Module\Workflow\Fact\FactsMother;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CardDocumentEntryConditionsTest extends TestCase
{
    private const string ARRIVED = '2026-10-22 10:00:00';

    #[DataProvider('cases')]
    public function test_it_evaluates_the_facts(Condition $condition, Facts $facts, bool $expected): void
    {
        self::assertSame($expected, $condition->evaluate($facts, ['tag' => 'design']));
    }

    /** @return iterable<string, array{Condition, Facts, bool}> */
    public static function cases(): iterable
    {
        $decisions = new CardDocumentEntryDecisionsOpen();
        $threads = new CardDocumentEntryThreadsOpen();
        $rejected = new CardDocumentRejectedBefore();
        $entered = new CardDocumentEnteredReviewInColumn();

        yield 'decisions, one unanswered at entry' => [$decisions, self::facts(self::document(decisions: 1)), true];
        yield 'decisions, none unanswered at entry' => [$decisions, self::facts(self::document(decisions: 0)), false];
        yield 'decisions, no entry check' => [$decisions, self::facts(self::document(check: false)), true];
        yield 'decisions, one of two documents is open' => [$decisions, self::facts(self::document(decisions: 0), self::document(id: 'two', decisions: 2)), true];
        yield 'decisions, a document with another tag is open' => [$decisions, self::facts(self::document(decisions: 0), self::document(id: 'two', tags: ['notes'], decisions: 2)), false];
        yield 'decisions, an approved document is no concern' => [$decisions, self::facts(self::document(status: 'approved', decisions: 3)), false];
        yield 'decisions, no document' => [$decisions, self::facts(), false];

        yield 'threads, one open at entry' => [$threads, self::facts(self::document(threads: 1)), true];
        yield 'threads, none open at entry' => [$threads, self::facts(self::document(threads: 0)), false];
        yield 'threads, no entry check' => [$threads, self::facts(self::document(check: false)), true];
        yield 'threads, decisions do not count' => [$threads, self::facts(self::document(decisions: 4, threads: 0)), false];
        yield 'threads, no document' => [$threads, self::facts(), false];

        yield 'rejected, a rejection on record' => [$rejected, self::facts(self::document(rejected: true)), true];
        yield 'rejected, none on record' => [$rejected, self::facts(self::document(rejected: false)), false];
        yield 'rejected, a document with another tag' => [$rejected, self::facts(self::document(tags: ['notes'], rejected: true)), false];
        yield 'rejected, a document in any status' => [$rejected, self::facts(self::document(status: 'approved', rejected: true)), true];
        yield 'rejected, no document' => [$rejected, self::facts(), false];

        yield 'entered, after the arrival' => [$entered, self::facts(self::document(enteredAt: '2026-10-22 10:05:00')), true];
        yield 'entered, at the arrival' => [$entered, self::facts(self::document(enteredAt: self::ARRIVED)), true];
        yield 'entered, before the arrival' => [$entered, self::facts(self::document(enteredAt: '2026-10-22 09:59:59')), false];
        yield 'entered, no entry check' => [$entered, self::facts(self::document(check: false)), false];
        yield 'entered, one of two documents is early' => [$entered, self::facts(self::document(enteredAt: '2026-10-22 10:05:00'), self::document(id: 'two', enteredAt: '2026-10-22 09:00:00')), false];
        yield 'entered, an approved document is no concern' => [$entered, self::facts(self::document(enteredAt: '2026-10-22 10:05:00'), self::document(id: 'two', status: 'approved', enteredAt: '2026-10-22 09:00:00')), true];
        yield 'entered, no arrival is known' => [$entered, self::factsArrivingAt(null, [self::document(enteredAt: '2026-10-22 10:05:00')]), false];
        yield 'entered, no document in review' => [$entered, self::facts(), false];
    }

    public function test_a_bound_document_hides_the_others_from_the_conditions_that_read_documents_in_review(): void
    {
        $open = self::document(id: 'open', decisions: 2);
        $clean = self::document(id: 'clean', decisions: 0);
        $facts = self::facts($open, $clean);

        self::assertTrue(new CardDocumentEntryDecisionsOpen()->evaluate($facts->withDocument($open), ['tag' => 'design']));
        self::assertFalse(new CardDocumentEntryDecisionsOpen()->evaluate($facts->withDocument($clean), ['tag' => 'design']));
    }

    #[DataProvider('waiting')]
    public function test_it_says_what_it_waits_for(Condition $condition, string $expectedKey): void
    {
        $message = $condition->waitingFor(['tag' => 'design']);

        self::assertSame($expectedKey, $message->getMessage());
        self::assertSame(['%tag%' => 'design'], $message->getParameters());
        self::assertSame(str_replace('workflow.waiting.', 'workflow.waiting.not.', $expectedKey), $condition->waitingFor(['tag' => 'design'], negated: true)->getMessage());
    }

    /** @return iterable<string, array{Condition, string}> */
    public static function waiting(): iterable
    {
        yield 'card.document.entry_decisions_open' => [new CardDocumentEntryDecisionsOpen(), 'workflow.waiting.card_document_entry_decisions_open'];
        yield 'card.document.entry_threads_open' => [new CardDocumentEntryThreadsOpen(), 'workflow.waiting.card_document_entry_threads_open'];
        yield 'card.document.rejected_before' => [new CardDocumentRejectedBefore(), 'workflow.waiting.card_document_rejected_before'];
        yield 'card.document.entered_review_in_column' => [new CardDocumentEnteredReviewInColumn(), 'workflow.waiting.card_document_entered_review_in_column'];
    }

    public function test_each_condition_names_its_key_the_tag_it_reads_and_the_facts_it_reads(): void
    {
        $keys = [];
        foreach ([new CardDocumentEntryDecisionsOpen(), new CardDocumentEntryThreadsOpen(), new CardDocumentRejectedBefore(), new CardDocumentEnteredReviewInColumn()] as $condition) {
            $keys[] = $condition::key();
            self::assertSame('design', $condition->documentTag(['tag' => 'design']));
            self::assertSame('workflow.source.board', $condition::source());
            self::assertSame('tag', $condition::parameters()[0]->name);
        }
        self::assertSame(['card.document.entry_decisions_open', 'card.document.entry_threads_open', 'card.document.rejected_before', 'card.document.entered_review_in_column'], $keys);
        self::assertSame([DocumentsFacts::class], new CardDocumentEntryDecisionsOpen()->reads(['tag' => 'design']));
        self::assertSame([DocumentsFacts::class], new CardDocumentRejectedBefore()->reads(['tag' => 'design']));
        self::assertSame([DocumentsFacts::class, ColumnArrivalFacts::class], new CardDocumentEnteredReviewInColumn()->reads(['tag' => 'design']));
    }

    /** @param list<string> $tags */
    private static function document(
        string $id = 'one',
        array $tags = ['design'],
        string $status = 'in-review',
        bool $check = true,
        int $decisions = 0,
        int $threads = 0,
        bool $rejected = false,
        string $enteredAt = '2026-10-22 10:30:00',
    ): DocumentFacts {
        return new DocumentFacts(
            tags: $tags,
            status: $status,
            id: $id,
            version: 1,
            enteredReviewAt: $check ? new \DateTimeImmutable($enteredAt) : null,
            entryUnansweredDecisions: $check ? $decisions : null,
            entryOpenThreads: $check ? $threads : null,
            rejectedBefore: $rejected,
        );
    }

    private static function facts(DocumentFacts ...$documents): Facts
    {
        return self::factsArrivingAt(new \DateTimeImmutable(self::ARRIVED), array_values($documents));
    }

    /** @param list<DocumentFacts> $documents */
    private static function factsArrivingAt(?\DateTimeImmutable $arrival, array $documents): Facts
    {
        return FactsMother::facts(
            card: FactsMother::card(documents: $documents),
            provided: [ColumnArrivalFacts::class => new ColumnArrivalFacts($arrival)],
        );
    }
}
