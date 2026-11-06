<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Workflow\Condition;

use App\Module\Board\Workflow\CardValueFacts;
use App\Module\Board\Workflow\CardValueWrite;
use App\Module\Board\Workflow\ColumnArrivalFacts;
use App\Module\Board\Workflow\Condition\CardDocumentNewestApprovalByWorkflow;
use App\Module\Board\Workflow\Condition\CardValueWrittenSinceApproval;
use App\Module\Board\Workflow\DocumentsFacts;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\DocumentFacts;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\ParameterType;
use App\Module\Workflow\Contract\ReadsDocumentTag;
use App\Tests\Module\Workflow\Fact\FactsMother;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApprovalConditionsTest extends TestCase
{
    private const string ARRIVED = '2026-10-22 10:00:00.500000';

    /** @return iterable<string, array{list<DocumentFacts>, bool}> */
    public static function newestApprovals(): iterable
    {
        yield 'the Workflow account approved the only document' => [[self::approved('one', '10:05:00.250000', true)], true];
        yield 'a person approved the only document' => [[self::approved('one', '10:05:00.250000', false)], false];
        yield 'the Workflow approval is the newest of two' => [[self::approved('one', '10:05:00.250000', true), self::approved('two', '10:04:00', false)], true];
        yield 'a person approved after the Workflow account' => [[self::approved('one', '10:04:00', true), self::approved('two', '10:05:00', false)], false];
        yield 'the order inside one second follows the microseconds' => [[self::approved('one', '10:05:00.200000', true), self::approved('two', '10:05:00.700000', false)], false];
        yield 'the order inside one second, the other way round' => [[self::approved('one', '10:05:00.700000', true), self::approved('two', '10:05:00.200000', false)], true];
        yield 'two approvals at one time, one by the Workflow account' => [[self::approved('one', '10:05:00', false), self::approved('two', '10:05:00', true)], true];
        yield 'two approvals at one time, none by the Workflow account' => [[self::approved('one', '10:05:00', false), self::approved('two', '10:05:00', false)], false];
        yield 'an older approval by the Workflow account is not enough' => [[self::approved('one', '10:01:00', true), self::approved('two', '10:05:00', false)], false];
        yield 'a document that is not approved does not count' => [[self::approved('one', '10:05:00', false), self::approved('two', '10:09:00', true, status: 'in-review')], false];
        yield 'a document with another tag does not count' => [[self::approved('one', '10:05:00', false), self::approved('two', '10:09:00', true, tags: ['notes'])], false];
        yield 'an approved document with no approval time' => [[new DocumentFacts(['product'], 'approved', 'one')], false];
        yield 'no document' => [[], false];
    }

    /** @param list<DocumentFacts> $documents */
    #[DataProvider('newestApprovals')]
    public function test_the_newest_approval_by_the_workflow_account(array $documents, bool $expected): void
    {
        $facts = FactsMother::facts(card: FactsMother::card(documents: $documents));

        self::assertSame($expected, new CardDocumentNewestApprovalByWorkflow()->evaluate($facts, ['tag' => 'product']));
    }

    public function test_a_bound_document_hides_the_others(): void
    {
        $workflow = self::approved('workflow', '10:05:00', true);
        $person = self::approved('person', '10:09:00', false);
        $facts = FactsMother::facts(card: FactsMother::card(documents: [$workflow, $person]));

        self::assertFalse(new CardDocumentNewestApprovalByWorkflow()->evaluate($facts, ['tag' => 'product']));
        self::assertTrue(new CardDocumentNewestApprovalByWorkflow()->evaluate($facts->withDocument($workflow), ['tag' => 'product']));
    }

    public function test_it_is_an_entry_check_condition_that_reads_the_tag_and_the_documents(): void
    {
        $condition = new CardDocumentNewestApprovalByWorkflow();

        self::assertSame('card.document.newest_approval_by_workflow', $condition::key());
        self::assertSame('workflow.source.board', $condition::source());
        self::assertInstanceOf(ReadsDocumentTag::class, $condition);
        self::assertSame('product', $condition->documentTag(['tag' => 'product']));
        self::assertSame([DocumentsFacts::class], $condition->reads(['tag' => 'product']));
        self::assertSame('workflow.waiting.card_document_newest_approval_by_workflow', $condition->waitingFor(['tag' => 'product'])->getMessage());
        self::assertSame('workflow.waiting.not.card_document_newest_approval_by_workflow', $condition->waitingFor(['tag' => 'product'], negated: true)->getMessage());
    }

    /**
     * @param ?string              $writtenAt  the write time, or null for a value nobody wrote
     * @param ?string              $arrivedAt  the arrival time of the card, or null for none
     * @param ?string              $approvedAt the time of the newest approval, or null for none
     * @param array<string, mixed> $params
     */
    #[DataProvider('writes')]
    public function test_a_value_written_since_the_approval(?string $writtenAt, Actor $writer, ?string $arrivedAt, ?string $approvedAt, array $params, bool $expected): void
    {
        $facts = self::writeFacts($writtenAt, $writer, $arrivedAt, null === $approvedAt ? [] : [self::approved('one', $approvedAt, true)]);

        self::assertSame($expected, new CardValueWrittenSinceApproval()->evaluate($facts, $params + ['key' => 'k', 'tag' => 'product']));
    }

    /** @return iterable<string, array{?string, Actor, ?string, ?string, array<string, mixed>, bool}> */
    public static function writes(): iterable
    {
        yield 'after the arrival and after the approval' => ['2026-10-22 10:10:00', Actor::Human, self::ARRIVED, '10:05:00', [], true];
        yield 'before the arrival' => ['2026-10-22 09:59:00', Actor::Human, self::ARRIVED, '10:05:00', [], false];
        yield 'after the arrival, before the approval' => ['2026-10-22 10:03:00', Actor::Human, self::ARRIVED, '10:05:00', [], false];
        yield 'at the arrival to the microsecond' => ['2026-10-22 10:00:00.500000', Actor::Human, self::ARRIVED, null, [], false];
        yield 'one microsecond after the arrival' => ['2026-10-22 10:00:00.500001', Actor::Human, self::ARRIVED, null, [], true];
        yield 'at the approval to the microsecond' => ['2026-10-22 10:05:00.250000', Actor::Human, self::ARRIVED, '10:05:00.250000', [], false];
        yield 'one microsecond after the approval' => ['2026-10-22 10:05:00.250001', Actor::Human, self::ARRIVED, '10:05:00.250000', [], true];
        yield 'in the second of the approval but before it' => ['2026-10-22 10:05:00.100000', Actor::Human, self::ARRIVED, '10:05:00.250000', [], false];
        yield 'with no approval, only the arrival counts' => ['2026-10-22 10:10:00', Actor::Human, self::ARRIVED, null, [], true];
        yield 'no known arrival' => ['2026-10-22 10:10:00', Actor::Human, null, '10:05:00', [], false];
        yield 'nobody wrote the value' => [null, Actor::Human, self::ARRIVED, '10:05:00', [], false];
        yield 'a person, by person' => ['2026-10-22 10:10:00', Actor::Human, self::ARRIVED, '10:05:00', ['byPerson' => true], true];
        yield 'an agent, by person' => ['2026-10-22 10:10:00', Actor::Agent, self::ARRIVED, '10:05:00', ['byPerson' => true], false];
        yield 'an api key, by person' => ['2026-10-22 10:10:00', Actor::ApiKey, self::ARRIVED, '10:05:00', ['byPerson' => true], false];
        yield 'the system, by person' => ['2026-10-22 10:10:00', Actor::System, self::ARRIVED, '10:05:00', ['byPerson' => true], false];
        yield 'an agent, by person off' => ['2026-10-22 10:10:00', Actor::Agent, self::ARRIVED, '10:05:00', ['byPerson' => false], true];
        yield 'an agent, no by person' => ['2026-10-22 10:10:00', Actor::Agent, self::ARRIVED, '10:05:00', [], true];
    }

    public function test_the_write_must_follow_the_newest_approval_of_every_approved_document_with_the_tag(): void
    {
        $facts = self::writeFacts('2026-10-22 10:06:00', Actor::Human, self::ARRIVED, [
            self::approved('one', '10:05:00', true),
            self::approved('two', '10:07:00', false),
            self::approved('three', '10:20:00', false, tags: ['notes']),
            self::approved('four', '10:30:00', false, status: 'in-review'),
        ]);

        self::assertFalse(new CardValueWrittenSinceApproval()->evaluate($facts, ['key' => 'k', 'tag' => 'product']));
        self::assertTrue(new CardValueWrittenSinceApproval()->evaluate($facts, ['key' => 'k', 'tag' => 'other']));
    }

    public function test_another_key_does_not_count(): void
    {
        $facts = self::writeFacts('2026-10-22 10:10:00', Actor::Human, self::ARRIVED, []);

        self::assertFalse(new CardValueWrittenSinceApproval()->evaluate($facts, ['key' => 'other', 'tag' => 'product']));
    }

    public function test_it_declares_its_parameters_and_the_facts_it_reads(): void
    {
        $condition = new CardValueWrittenSinceApproval();
        $parameters = $condition::parameters();

        self::assertSame('card.value.written_since_approval', $condition::key());
        self::assertSame('workflow.source.board', $condition::source());
        self::assertSame(['key', 'tag', 'byPerson'], array_map(static fn ($parameter): string => $parameter->name, $parameters));
        self::assertSame([ParameterType::ValueKey, ParameterType::String, ParameterType::Bool], array_map(static fn ($parameter): ParameterType => $parameter->type, $parameters));
        self::assertSame([true, true, false], array_map(static fn ($parameter): bool => $parameter->required, $parameters));
        self::assertSame([CardValueFacts::class, ColumnArrivalFacts::class, DocumentsFacts::class], $condition->reads(['key' => 'k', 'tag' => 'product']));
        self::assertNotContains(ReadsDocumentTag::class, class_implements($condition));
    }

    public function test_it_says_what_it_waits_for(): void
    {
        $condition = new CardValueWrittenSinceApproval();
        $parameters = ['%key%' => 'k', '%tag%' => 'product'];

        $plain = $condition->waitingFor(['key' => 'k', 'tag' => 'product']);
        self::assertSame('workflow.waiting.card_value_written_since_approval', $plain->getMessage());
        self::assertSame($parameters, $plain->getParameters());
        self::assertSame('workflow.waiting.not.card_value_written_since_approval', $condition->waitingFor(['key' => 'k', 'tag' => 'product'], negated: true)->getMessage());
        self::assertSame('workflow.waiting.card_value_written_since_approval_by_person', $condition->waitingFor(['key' => 'k', 'tag' => 'product', 'byPerson' => true])->getMessage());
        self::assertSame('workflow.waiting.not.card_value_written_since_approval_by_person', $condition->waitingFor(['key' => 'k', 'tag' => 'product', 'byPerson' => true], negated: true)->getMessage());
    }

    /** @param list<string> $tags */
    private static function approved(string $id, string $at, bool $byWorkflow, array $tags = ['product'], string $status = 'approved'): DocumentFacts
    {
        return new DocumentFacts(
            tags: $tags,
            status: $status,
            id: $id,
            newestApprovalAt: new \DateTimeImmutable(str_contains($at, '-') ? $at : '2026-10-22 '.$at),
            newestApprovalByWorkflow: $byWorkflow,
        );
    }

    /** @param list<DocumentFacts> $documents */
    private static function writeFacts(?string $writtenAt, Actor $writer, ?string $arrivedAt, array $documents): Facts
    {
        return FactsMother::facts(
            card: FactsMother::card(documents: $documents),
            provided: [
                CardValueFacts::class => new CardValueFacts(null === $writtenAt ? [] : ['k' => true], null === $writtenAt ? [] : ['k' => new CardValueWrite(new \DateTimeImmutable($writtenAt), $writer)]),
                ColumnArrivalFacts::class => new ColumnArrivalFacts(null === $arrivedAt ? null : new \DateTimeImmutable($arrivedAt)),
            ],
        );
    }
}
