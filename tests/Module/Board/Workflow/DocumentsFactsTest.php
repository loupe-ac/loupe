<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Workflow;

use App\Module\Board\Workflow\DocumentsFacts;
use App\Module\Board\Workflow\ParentDocumentsFacts;
use App\Module\Workflow\Contract\DocumentFacts;
use App\Module\Workflow\Contract\SwapsDocumentStatus;
use App\Tests\Module\Workflow\Fact\FactsMother;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DocumentsFactsTest extends TestCase
{
    /** @param class-string<DocumentsFacts|ParentDocumentsFacts> $class */
    #[DataProvider('factGroups')]
    public function test_a_swap_changes_only_the_named_document(string $class): void
    {
        $versionAt = new \DateTimeImmutable('2026-10-01 09:00:00');
        $facts = new $class([new DocumentFacts(['a'], 'in-review', 'one', 'Design', $versionAt, 3), new DocumentFacts(['a'], 'draft', 'two')]);

        $swapped = $facts->withDocumentStatus('one', 'approved');

        self::assertInstanceOf($class, $swapped);
        self::assertSame(['approved', 'draft'], array_map(static fn (DocumentFacts $document): string => $document->status, $swapped->documents));
        self::assertSame(['a'], $swapped->documents[0]->tags);
        self::assertSame('Design', $swapped->documents[0]->title);
        self::assertSame($versionAt, $swapped->documents[0]->versionAt);
        self::assertSame(3, $swapped->documents[0]->version);
        self::assertSame('in-review', $facts->documents[0]->status);
    }

    /** @param class-string<DocumentsFacts|ParentDocumentsFacts> $class */
    #[DataProvider('factGroups')]
    public function test_an_unknown_document_leaves_the_facts_as_they_were(string $class): void
    {
        $facts = new $class([new DocumentFacts(['a'], 'in-review', 'one')]);

        self::assertEquals($facts, $facts->withDocumentStatus('missing', 'approved'));
    }

    public function test_the_facts_list_the_card_documents_and_bind_one_of_them(): void
    {
        $documents = new DocumentsFacts([new DocumentFacts(['a'], 'in-review', 'one'), new DocumentFacts(['a'], 'in-review', 'two')]);
        $facts = FactsMother::facts(provided: [DocumentsFacts::class => $documents, ParentDocumentsFacts::class => new ParentDocumentsFacts([new DocumentFacts(['a'], 'approved', 'parent')])]);

        $bound = $facts->withDocument($documents->documents[1]);

        self::assertSame(['one', 'two'], array_map(static fn (DocumentFacts $document): string => $document->id, $facts->documents()), 'the parent documents are not the card documents');
        self::assertSame($facts->documents(), $bound->documents(), 'a bound document hides no document from a rule');
        self::assertSame([true, true], [$facts->readsDocument('one'), $facts->readsDocument('two')]);
        self::assertSame([false, true], [$bound->readsDocument('one'), $bound->readsDocument('two')]);
        self::assertTrue($bound->withPullRequest(null)->withProvided([])->readsDocument('two') && !$bound->withPullRequest(null)->readsDocument('one'), 'a copy keeps the bound document');
    }

    /** @return iterable<string, array{class-string<SwapsDocumentStatus>}> */
    public static function factGroups(): iterable
    {
        yield 'the documents of the card' => [DocumentsFacts::class];
        yield 'the documents of the parent' => [ParentDocumentsFacts::class];
    }
}
